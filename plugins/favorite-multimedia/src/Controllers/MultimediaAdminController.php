<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\Album;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\DownloadSource;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Favorite;
use FavoriteCMS\Multimedia\Models\Genre;
use FavoriteCMS\Multimedia\Models\MediaProcessingJob;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Season;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Models\MediaLocalization;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\FFmpegService;
use FavoriteCMS\Multimedia\Services\MediaLanguageService;
use FavoriteCMS\Multimedia\Services\MediaProcessingService;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;
use FavoriteCMS\Multimedia\Services\MultimediaNotificationService;
use FavoriteCMS\Multimedia\Services\MultimediaReleaseService;
use FavoriteCMS\Multimedia\Models\MediaStorageFile;
use FavoriteCMS\Multimedia\Services\MediaStorageService;
use FavoriteCMS\Multimedia\Storage\MediaStorageManager;
use FavoriteCMS\Multimedia\Services\MultimediaSubscriptionService;
use FavoriteCMS\Multimedia\Services\MultimediaAnalyticsService;
use FavoriteCMS\Multimedia\Services\MediaSourceService;
use FavoriteCMS\Multimedia\Theme\ThemeConfig;
use FavoriteCMS\Multimedia\Theme\ThemeExportImportService;
use FavoriteCMS\Multimedia\Theme\ThemeManager;
use FavoriteCMS\Multimedia\Theme\ThemePresetRegistry;
use FavoriteCMS\Multimedia\Theme\ThemePreviewService;
use FavoriteCMS\Multimedia\Theme\ThemeTokenResolver;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageConfig;
use FavoriteCMS\Multimedia\Theme\Homepage\HomepageManager;
use FavoriteCMS\Multimedia\Theme\Homepage\SectionRegistry;
use FavoriteCMS\Multimedia\Theme\Homepage\SectionRenderer;
use FavoriteCMS\Multimedia\Services\UploadSecurityService;

class MultimediaAdminController
{
    private Application $app;
    private Database $db;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->db = $app->make(Database::class);
    }

    /**
     * Resolve the active user safely across session types and contexts.
     */
    protected function currentUser(): ?User
    {
        return MultimediaPermission::resolveUser();
    }

    /**
     * Entry dispatcher for admin pages.
     */
    public function handle(Request $request, string $section = 'dashboard'): Response|string
    {
        $user = $this->currentUser();
        if (!$user) {
            return Response::redirect('/admin/login');
        }
        if (MultimediaPermission::isSuspendedUser($user)) {
            return Response::make('<h1>403 Access Denied</h1><p>Your account does not have permission to access multimedia administration.</p>', 403);
        }

        $hasAdminView = MultimediaPermission::can(MultimediaPermission::VIEW, $user);
        $canSubmit = MultimediaPermission::canUserSubmit(null, $user);

        // If user has neither full catalog view nor creator submission capability, deny access strictly
        if (!$hasAdminView && !$canSubmit) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to view Multimedia.</p>', 403);
        }

        // For non-admin/non-moderator creators:
        if (!$hasAdminView) {
            if ($section === 'dashboard') {
                return $this->mySubmissions($request);
            }

            // Admin-only sections: redirect safely to My Submissions with friendly toast/notice
            // Note: 'analytics' is handled for authors via author-scoped view below
            if (in_array($section, [
                'genres', 'artists', 'sources', 'subtitles', 'access',
                'settings', 'moderation', 'releases', 'processing',
                'storage', 'localizations', 'theme', 'theme_preview'
            ], true)) {
                $_SESSION['flash_info'] = 'You can manage your own multimedia submissions here.';
                return Response::redirect('/admin/page/multimedia-my-submissions');
            }

            // Direct index catalog access without new or edit: redirect safely to My Submissions
            if (in_array($section, ['movies', 'series', 'seasons', 'episodes', 'songs', 'albums', 'playlists'], true)) {
                $isPost = ($request->method() === 'POST');
                $isNew = (bool)$request->get('new');
                $isEdit = (bool)$request->get('edit');
                $action = (string)$request->post('action', $request->get('action', ''));

                if (!$isPost && !$isNew && !$isEdit && !in_array($action, ['create', 'edit', 'delete'], true)) {
                    $_SESSION['flash_info'] = 'You can manage your own multimedia submissions here.';
                    return Response::redirect('/admin/page/multimedia-my-submissions');
                }
            }
        }

        // Restrict sensitive sections to users with required capabilities
        // Settings / Storage / Theme / Localizations: Super Admin = YES, Admin = YES, Editor = NO, Moderator = NO
        if (in_array($section, ['settings', 'storage', 'theme', 'theme_preview', 'localizations'], true) && !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to access multimedia settings or configuration.</p>', 403);
        }
        // Genres & Artists: Super Admin = YES, Admin = YES, Editor = YES, Moderator = NO, Author = NO
        if (in_array($section, ['genres', 'artists'], true) && !$user->hasRole('super-admin') && !$user->hasRole('admin') && !$user->hasRole('editor')) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage genres or artists.</p>', 403);
        }
        // Access Rules: Super Admin = YES, Admin = YES, Editor = Restricted, Moderator = NO
        if ($section === 'access' && !MultimediaPermission::can(MultimediaPermission::MANAGE_ACCESS, $user)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to access multimedia access rules.</p>', 403);
        }
        if ($section === 'analytics' && !MultimediaPermission::can(MultimediaPermission::VIEW_ANALYTICS, $user)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to view multimedia analytics.</p>', 403);
        }
        if ($section === 'moderation' && !MultimediaPermission::can(MultimediaPermission::MODERATE, $user)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to moderate multimedia content.</p>', 403);
        }

        return match ($section) {
            'dashboard'  => $this->dashboard($request),
            'movies'     => $this->movies($request),
            'series'     => $this->series($request),
            'seasons'    => $this->seasons($request),
            'episodes'   => $this->episodes($request),
            'songs'      => $this->songs($request),
            'playlists'  => $this->playlists($request),
            'genres'     => $this->genres($request),
            'artists'    => $this->artists($request),
            'albums'     => $this->albums($request),
            'sources'    => $this->sources($request),
            'subtitles'  => $this->subtitles($request),
            'access'     => $this->accessRules($request),
            'analytics'  => $this->analytics($request),
            'settings'   => $this->settings($request),
            'moderation' => $this->moderation($request),
            'releases'   => $this->releases($request),
            'processing'    => $this->processing($request),
            'storage'       => $this->storage($request),
            'localizations' => $this->localizations($request),
            'theme'         => $this->themeStudio($request),
            'theme_preview' => $this->themePreview($request),
            'my_submissions', 'my-submissions' => $this->mySubmissions($request),
            default         => $this->dashboard($request),
        };
    }

    // 1. Dashboard
    public function dashboard(Request $request): string
    {
        $stats = AnalyticsEvent::getStats();
        $recentMovies = Movie::published(5);
        $recentSeries = Series::published(5);
        $recentSongs = Song::published(5);
        $recentPlaylists = Playlist::published(5);

        return $this->renderView('dashboard', [
            'stats'           => $stats,
            'recentMovies'    => $recentMovies,
            'recentSeries'    => $recentSeries,
            'recentSongs'     => $recentSongs,
            'recentPlaylists' => $recentPlaylists,
        ]);
    }

    // 1b. My Submissions (Creator scoped dashboard)
    public function mySubmissions(Request $request): Response|string
    {
        $user = $this->currentUser();
        if (!$user) {
            return Response::redirect('/admin/login');
        }

        $userId = (int)$user->id;

        $tables = [
            'movie'    => 'multimedia_movies',
            'series'   => 'multimedia_series',
            'episode'  => 'multimedia_episodes',
            'song'     => 'multimedia_songs',
            'album'    => 'multimedia_albums',
            'playlist' => 'multimedia_playlists',
        ];

        // Handle Resubmit POST Action
        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            $action = (string)$request->post('action', '');

            if ($action === 'resubmit') {
                $cType = strtolower((string)$request->post('content_type', ''));
                $itemId = (int)$request->post('id', 0);

                if (!isset($tables[$cType]) || $itemId <= 0) {
                    $_SESSION['flash_error'] = 'Invalid multimedia item.';
                    return Response::redirect('/admin/page/multimedia-my-submissions');
                }

                $targetTable = $tables[$cType];
                $item = $this->db->selectOne("SELECT id, user_id, status FROM {$targetTable} WHERE id = ?", [$itemId]);
                if (!$item || (int)$item->user_id !== $userId) {
                    $_SESSION['flash_error'] = 'You can only resubmit your own submissions.';
                    return Response::redirect('/admin/page/multimedia-my-submissions');
                }

                if ($item->status !== 'rejected') {
                    $_SESSION['flash_error'] = 'Only rejected items can be resubmitted for review.';
                    return Response::redirect('/admin/page/multimedia-my-submissions');
                }

                $this->db->update($targetTable, [
                    'status'           => 'pending',
                    'rejected_by'      => null,
                    'rejected_at'      => null,
                    'rejection_reason' => null,
                    'approved_by'      => null,
                    'approved_at'      => null,
                ], ['id' => $itemId]);

                $_SESSION['flash_success'] = 'Your changes were submitted for review.';
                return Response::redirect('/admin/page/multimedia-my-submissions');
            }

            if ($action === 'delete') {
                $cType = strtolower((string)$request->post('content_type', 'movie'));
                $itemId = (int)$request->post('id', 0);
                $fallback = '/admin/page/multimedia-my-submissions';
                if (!isset($tables[$cType]) || $itemId <= 0) {
                    return $this->deleteDeniedResponse($request, "You cannot delete this {$cType}.", $fallback);
                }
                $item = match ($cType) {
                    'movie'    => Movie::find($itemId),
                    'series'   => Series::find($itemId),
                    'episode'  => Episode::find($itemId),
                    'song'     => Song::find($itemId),
                    'album'    => Album::find($itemId),
                    'playlist' => Playlist::find($itemId),
                    default    => null,
                };
                if (!$item || !MultimediaPermission::canDeleteContent($item, $user)) {
                    return $this->deleteDeniedResponse($request, "You cannot delete this {$cType}.", $fallback);
                }
                $targetTable = $tables[$cType];
                $this->db->delete($targetTable, ['id' => $itemId]);
                return $this->deleteSuccessResponse($request, ucfirst($cType) . ' deleted successfully.', $fallback);
            }
        }

        $filterStatus = (string)$request->get('status', 'all');
        if (!in_array($filterStatus, ['all', 'pending', 'published', 'draft', 'rejected'], true)) {
            $filterStatus = 'all';
        }

        $filterType = (string)$request->get('type', 'all');
        if (!in_array($filterType, ['all', 'movie', 'series', 'episode', 'song', 'album', 'playlist'], true)) {
            $filterType = 'all';
        }

        $counts = [
            'all'       => 0,
            'pending'   => 0,
            'published' => 0,
            'draft'     => 0,
            'rejected'  => 0,
        ];

        $typeCounts = [
            'all'      => 0,
            'movie'    => 0,
            'series'   => 0,
            'episode'  => 0,
            'song'     => 0,
            'album'    => 0,
            'playlist' => 0,
        ];

        $unions = [];
        foreach ($tables as $cType => $tbl) {
            $unions[] = "SELECT '{$cType}' as content_type, id, title, slug, status, user_id, created_at, updated_at, rejection_reason FROM {$tbl} WHERE user_id = {$userId}";
        }
        $unionSql = implode(" UNION ALL ", $unions);

        $allRows = $this->db->select("SELECT * FROM ({$unionSql}) as sub ORDER BY updated_at DESC, created_at DESC");

        foreach ($allRows as $row) {
            $st = (string)($row->status ?? 'pending');
            $ct = (string)($row->content_type ?? '');

            $counts['all']++;
            if (isset($counts[$st])) {
                $counts[$st]++;
            }

            $typeCounts['all']++;
            if (isset($typeCounts[$ct])) {
                $typeCounts[$ct]++;
            }
        }

        $filteredItems = [];
        foreach ($allRows as $row) {
            $st = (string)($row->status ?? 'pending');
            $ct = (string)($row->content_type ?? '');

            if ($filterStatus !== 'all' && $st !== $filterStatus) {
                continue;
            }
            if ($filterType !== 'all' && $ct !== $filterType) {
                continue;
            }
            $filteredItems[] = $row;
        }

        return $this->renderView('my-submissions', [
            'filterStatus' => $filterStatus,
            'filterType'   => $filterType,
            'counts'       => $counts,
            'typeCounts'   => $typeCounts,
            'items'        => $filteredItems,
        ]);
    }

    // 2. Movies CRUD
    public function movies(Request $request): Response|string
    {
        $user = $this->currentUser();
        $isModerator = MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
        $canPublish = MultimediaPermission::can(MultimediaPermission::PUBLISH, $user);
        $action = (string)$request->post('action', $request->get('action', (int)$request->get('edit', 0) > 0 ? 'edit' : 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);

            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', (int)$request->get('edit', 0));
                $editMovie = ($id > 0) ? Movie::find($id) : null;
                if ($id > 0) {
                    if (!$editMovie || !MultimediaPermission::canEditContent($editMovie, $user)) {
                        return Response::make('<h1>403 Forbidden - You cannot edit this movie</h1>', 403);
                    }
                } else {
                    if (!MultimediaPermission::canUserSubmit('movie', $user)) {
                        return Response::make('<h1>403 Forbidden - Movie submissions disabled for your role</h1>', 403);
                    }
                }

                $title = trim((string)$request->post('title', ''));
                $slug = trim((string)$request->post('slug', '')) ?: str_slug($title);
                $pubFields = $this->resolvePublishingFields($request, 'multimedia_movies', $id);

                $ownerId = ($id > 0 && $editMovie) ? ((int)($editMovie->user_id ?? ($user ? $user->id : 1)) ?: (int)($user ? $user->id : 1)) : (int)($user ? $user->id : 1);

                $data = [
                    'user_id'           => $ownerId,
                    'title'             => $title,
                    'slug'              => $slug,
                    'description'       => trim((string)$request->post('description', '')),
                    'poster'            => trim((string)$request->post('poster', '')),
                    'backdrop'          => trim((string)$request->post('backdrop', '')),
                    'release_year'      => (int)$request->post('release_year', 0) ?: null,
                    'release_date'      => trim((string)$request->post('release_date', '')) ?: null,
                    'language'          => trim((string)$request->post('language', '')),
                    'original_language' => trim((string)$request->post('original_language', $request->post('language', 'en'))),
                    'country'           => trim((string)$request->post('country', '')),
                    'duration'          => (int)$request->post('duration', 0),
                    'director'          => trim((string)$request->post('director', '')),
                    'cast'              => trim((string)$request->post('cast', '')),
                    'trailer_url'       => trim((string)$request->post('trailer_url', '')),
                    'featured'          => (int)$request->post('featured', 0),
                    'status'            => ($id > 0) ? ($editMovie->status ?? 'draft') : 'draft', // Order A5: evaluate publication after source persistence
                    'publish_at'        => $pubFields['publish_at'],
                    'published_at'      => $pubFields['published_at'],
                    'unpublish_at'      => $pubFields['unpublish_at'],
                    'access_mode'       => (string)$request->post('access_mode', 'public'),
                    'download_policy'   => (string)$request->post('download_policy', 'inherit'),
                    'download_url'      => trim((string)$request->post('download_url', '')) ?: null,
                    'seo_title'         => trim((string)$request->post('seo_title', '')),
                    'seo_description'   => trim((string)$request->post('seo_description', '')),
                ];

                if ($id > 0) {
                    $this->db->update('multimedia_movies', $data, ['id' => $id]);
                    $movieId = $id;
                } else {
                    $movieId = (int)$this->db->insert('multimedia_movies', $data);
                }

                // Handle direct poster upload if provided
                $posterUploaded = $this->handleImageUpload($request, 'poster_file', 'movie', $movieId, 'posters');
                if ($posterUploaded !== null) {
                    $this->db->update('multimedia_movies', ['poster' => $posterUploaded], ['id' => $movieId]);
                }

                // Handle direct backdrop upload if provided
                $backdropUploaded = $this->handleImageUpload($request, 'backdrop_file', 'movie', $movieId, 'backdrops');
                if ($backdropUploaded !== null) {
                    $this->db->update('multimedia_movies', ['backdrop' => $backdropUploaded], ['id' => $movieId]);
                }

                // Handle direct video source upload or URL (Order A5: step 4)
                $sourceResult = $this->handleMediaSourceUploadOrUrl($request, 'movie', $movieId);

                // Handle direct subtitle track upload
                $this->handleSubtitleUpload($request, 'movie', $movieId);

                // Reload source list from database (Order A5: step 5)
                $currentSources = MediaSource::getForContent('movie', $movieId, false);
                $hasSources = !empty($currentSources);

                // Evaluate readiness and process Publish Now / Pending
                if ($pubFields['status'] === 'published') {
                    if ($hasSources) {
                        $this->db->update('multimedia_movies', [
                            'status'           => 'published',
                            'published_at'     => $pubFields['published_at'] ?? gmdate('Y-m-d H:i:s'),
                            'approved_by'      => $user->id,
                            'approved_at'      => gmdate('Y-m-d H:i:s'),
                            'rejected_by'      => null,
                            'rejected_at'      => null,
                            'rejection_reason' => null,
                        ], ['id' => $movieId]);
                        $_SESSION['flash_success'] = ($id > 0) ? 'Movie updated successfully.' : 'Movie created successfully.';
                        unset($_SESSION['flash_error'], $_SESSION['flash_warning']);
                    } else {
                        $this->db->update('multimedia_movies', ['status' => 'draft'], ['id' => $movieId]);
                        unset($_SESSION['flash_success']);
                        $exactReason = !empty($_SESSION['flash_error']) ? $_SESSION['flash_error'] : 'no video source is attached';
                        $_SESSION['flash_error'] = 'Notice: Movie was saved as Draft because no playable video source could be attached (' . $exactReason . ').';
                        $_SESSION['flash_warning'] = 'Notice: Movie was saved as Draft because no video source is attached (' . $exactReason . ').';
                    }
                } elseif ($pubFields['status'] === 'pending') {
                    $this->db->update('multimedia_movies', [
                        'status'           => 'pending',
                        'approved_by'      => null,
                        'approved_at'      => null,
                        'rejected_by'      => null,
                        'rejected_at'      => null,
                        'rejection_reason' => null,
                    ], ['id' => $movieId]);
                    if (!empty($pubFields['was_published'])) {
                        $_SESSION['flash_warning'] = 'Content updated. Because you are not a moderator, your edit returned this item to Pending Review and it will remain unpublished until re-approved.';
                        $_SESSION['flash_success'] = 'Your changes were submitted for review.';
                    } else {
                        $_SESSION['flash_success'] = 'Movie submitted for review successfully.';
                    }
                } else {
                    $this->db->update('multimedia_movies', ['status' => $pubFields['status']], ['id' => $movieId]);
                    $_SESSION['flash_success'] = ($id > 0) ? 'Movie updated successfully.' : 'Movie saved successfully.';
                }

                // Sync genres
                $genres = (array)$request->post('genres', []);
                Genre::syncForContent('movie', $movieId, $genres);

                // Handle multiple download sources
                $this->handleDownloadSources($request, 'movie', $movieId);

                return Response::redirect($isModerator ? '/admin/page/multimedia-movies' : '/admin/page/multimedia-my-submissions');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                $movie = ($id > 0) ? Movie::find($id) : null;
                $fallback = $isModerator ? '/admin/page/multimedia-movies' : '/admin/page/multimedia-my-submissions';
                if (!$movie || !MultimediaPermission::canDeleteContent($movie, $user)) {
                    return $this->deleteDeniedResponse($request, 'You cannot delete this movie.', $fallback);
                }
                if ($id > 0) {
                    $this->db->delete('multimedia_movies', ['id' => $id]);
                    $this->db->delete('multimedia_sources', ['content_type' => 'movie', 'content_id' => $id]);
                    $this->db->delete('multimedia_subtitles', ['content_type' => 'movie', 'content_id' => $id]);
                    try { $this->db->delete('multimedia_download_sources', ['content_type' => 'movie', 'content_id' => $id]); } catch (\Throwable) {}
                    $this->db->delete('multimedia_content_genres', ['content_type' => 'movie', 'content_id' => $id]);
                    PlaybackProgress::deleteForContent('movie', $id);
                    Favorite::deleteForContent('movie', $id);
                    MultimediaEngagementService::deleteForContent('movie', $id);
                    MediaLocalization::deleteForContent('movie', $id);
                }
                return $this->deleteSuccessResponse($request, 'Movie deleted successfully.', $fallback);
            }

            if ($action === 'bulk') {
                $bulkAction = trim((string)$request->post('bulk_action', ''));
                $rawIds = (array)$request->post('ids', []);
                $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($id) => $id > 0)));

                if (empty($ids)) {
                    $_SESSION['flash_error'] = 'No movies were selected for bulk action.';
                    return Response::redirect('/admin/page/multimedia-movies');
                }

                if ($bulkAction === 'delete') {
                    $deleted = 0;
                    foreach ($ids as $id) {
                        $m = Movie::find($id);
                        if (!$m || !MultimediaPermission::canDeleteContent($m, $user)) {
                            continue;
                        }
                        $this->db->delete('multimedia_movies', ['id' => $id]);
                        $this->db->delete('multimedia_sources', ['content_type' => 'movie', 'content_id' => $id]);
                        $this->db->delete('multimedia_subtitles', ['content_type' => 'movie', 'content_id' => $id]);
                        try { $this->db->delete('multimedia_download_sources', ['content_type' => 'movie', 'content_id' => $id]); } catch (\Throwable) {}
                        $this->db->delete('multimedia_content_genres', ['content_type' => 'movie', 'content_id' => $id]);
                        PlaybackProgress::deleteForContent('movie', $id);
                        Favorite::deleteForContent('movie', $id);
                        MultimediaEngagementService::deleteForContent('movie', $id);
                        MediaLocalization::deleteForContent('movie', $id);
                        $deleted++;
                    }
                    $_SESSION['flash_success'] = "{$deleted} movie(s) deleted successfully.";
                } elseif ($bulkAction === 'publish') {
                    if (!$canPublish) {
                        return Response::make('<h1>403 Forbidden - Publishing requires moderator or admin role</h1>', 403);
                    }
                    $published = 0;
                    $drafted = 0;
                    foreach ($ids as $id) {
                        $sources = MediaSource::getForContent('movie', $id, false);
                        if (!empty($sources)) {
                            $this->db->update('multimedia_movies', [
                                'status'           => 'published',
                                'published_at'     => gmdate('Y-m-d H:i:s'),
                                'approved_by'      => $user->id,
                                'approved_at'      => gmdate('Y-m-d H:i:s'),
                                'rejected_by'      => null,
                                'rejected_at'      => null,
                                'rejection_reason' => null,
                            ], ['id' => $id]);
                            $published++;
                        } else {
                            $this->db->update('multimedia_movies', ['status' => 'draft'], ['id' => $id]);
                            $drafted++;
                        }
                    }
                    if ($drafted > 0) {
                        $_SESSION['flash_warning'] = "{$published} movie(s) published. {$drafted} movie(s) kept as Draft because no playable media source is attached.";
                    } else {
                        $_SESSION['flash_success'] = "{$published} movie(s) published successfully.";
                    }
                } elseif ($bulkAction === 'draft') {
                    $updated = 0;
                    foreach ($ids as $id) {
                        $m = Movie::find($id);
                        if (!$m || !MultimediaPermission::canEditContent($m, $user)) {
                            continue;
                        }
                        $this->db->update('multimedia_movies', ['status' => 'draft'], ['id' => $id]);
                        $updated++;
                    }
                    $_SESSION['flash_success'] = "{$updated} movie(s) moved to Draft.";
                } else {
                    $_SESSION['flash_error'] = 'Invalid bulk action specified.';
                }

                return Response::redirect('/admin/page/multimedia-movies');
            }
        }

        if ($request->get('new') && !$isModerator && !MultimediaPermission::canUserSubmit('movie', $user)) {
            $_SESSION['flash_error'] = 'Movie submissions are currently disabled.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }

        $editId = (int)$request->get('edit', 0);
        $editMovie = ($editId > 0) ? Movie::find($editId) : null;
        if ($editMovie && !$isModerator && !MultimediaPermission::canEditContent($editMovie, $user)) {
            $_SESSION['flash_error'] = 'You do not have permission to edit this multimedia item.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }
        $items = $isModerator ? Movie::all() : Movie::forUser((int)$user->id);
        $genres = Genre::all();

        $mediaStatuses = [];
        $defaultSources = [];
        foreach ($items as $item) {
            $mediaStatuses[$item->id] = $this->getMediaStatusForContent('movie', (int)$item->id);
            $defaultSources[$item->id] = MediaSource::getDefault('movie', (int)$item->id);
        }

        if ($editMovie && !isset($mediaStatuses[$editMovie->id])) {
            $mediaStatuses[$editMovie->id] = $this->getMediaStatusForContent('movie', (int)$editMovie->id);
            $defaultSources[$editMovie->id] = MediaSource::getDefault('movie', (int)$editMovie->id);
        }

        $uploadMax = ini_get('upload_max_filesize') ?: '2M';
        $postMax = ini_get('post_max_size') ?: '8M';
        $ffmpegActive = false;
        try {
            $ffmpegActive = class_exists(FFmpegService::class) && FFmpegService::isAvailable();
        } catch (\Throwable) {
            $ffmpegActive = false;
        }

        return $this->renderView('movies', [
            'items'           => $items,
            'editMovie'       => $editMovie,
            'genres'          => $genres,
            'mediaStatuses'   => $mediaStatuses,
            'defaultSources'  => $defaultSources,
            'attachedSources' => $editMovie ? MediaSource::getForContent('movie', (int)$editMovie->id, false) : [],
            'downloadSources' => $editMovie ? DownloadSource::getForContent('movie', (int)$editMovie->id, false) : [],
            'uploadMax'       => $uploadMax,
            'postMax'         => $postMax,
            'ffmpegActive'    => $ffmpegActive,
        ]);
    }

    // 3. Series CRUD
    public function series(Request $request): Response|string
    {
        $user = $this->currentUser();
        $isModerator = MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
        $canPublish = MultimediaPermission::can(MultimediaPermission::PUBLISH, $user);
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $editSeries = ($id > 0) ? Series::find($id) : null;
                if ($id > 0) {
                    if (!$editSeries || !MultimediaPermission::canEditContent($editSeries, $user)) {
                        return Response::make('<h1>403 Forbidden - You cannot edit this series</h1>', 403);
                    }
                } else {
                    if (!MultimediaPermission::canUserSubmit('series', $user)) {
                        return Response::make('<h1>403 Forbidden - Series submissions disabled for your role</h1>', 403);
                    }
                }

                $title = trim((string)$request->post('title', ''));
                $slug = trim((string)$request->post('slug', '')) ?: str_slug($title);
                $pubFields = $this->resolvePublishingFields($request, 'multimedia_series', $id);

                $ownerId = ($id > 0 && $editSeries) ? ((int)($editSeries->user_id ?? ($user ? $user->id : 1)) ?: (int)($user ? $user->id : 1)) : (int)($user ? $user->id : 1);

                $data = [
                    'user_id'           => $ownerId,
                    'title'             => $title,
                    'slug'              => $slug,
                    'description'       => trim((string)$request->post('description', '')),
                    'poster'            => trim((string)$request->post('poster', '')),
                    'backdrop'          => trim((string)$request->post('backdrop', '')),
                    'release_year'      => (int)$request->post('release_year', 0) ?: null,
                    'language'          => trim((string)$request->post('language', '')),
                    'original_language' => trim((string)$request->post('original_language', $request->post('language', 'en'))),
                    'country'           => trim((string)$request->post('country', '')),
                    'director'          => trim((string)$request->post('director', '')),
                    'cast'              => trim((string)$request->post('cast', '')),
                    'trailer_url'       => trim((string)$request->post('trailer_url', '')),
                    'featured'          => (int)$request->post('featured', 0),
                    'status'            => $pubFields['status'],
                    'publish_at'        => $pubFields['publish_at'],
                    'published_at'      => $pubFields['published_at'],
                    'unpublish_at'      => $pubFields['unpublish_at'],
                    'access_mode'       => (string)$request->post('access_mode', 'public'),
                    'download_policy'   => (string)$request->post('download_policy', 'inherit'),
                    'seo_title'         => trim((string)$request->post('seo_title', '')),
                    'seo_description'   => trim((string)$request->post('seo_description', '')),
                ];

                if ($pubFields['status'] === 'published') {
                    $data['approved_by'] = $user->id;
                    $data['approved_at'] = gmdate('Y-m-d H:i:s');
                    $data['rejected_by'] = null;
                    $data['rejected_at'] = null;
                    $data['rejection_reason'] = null;
                } elseif ($pubFields['status'] === 'pending') {
                    $data['approved_by'] = null;
                    $data['approved_at'] = null;
                    $data['rejected_by'] = null;
                    $data['rejected_at'] = null;
                    $data['rejection_reason'] = null;
                }

                if ($id > 0) {
                    $this->db->update('multimedia_series', $data, ['id' => $id]);
                    $seriesId = $id;
                    if (!empty($pubFields['was_published']) && !$canPublish) {
                        $_SESSION['flash_warning'] = 'Content updated. Because you are not a moderator, your edit returned this series to Pending Review and it will remain unpublished until re-approved.';
                        $_SESSION['flash_success'] = 'Your changes were submitted for review.';
                    } else {
                        $_SESSION['flash_success'] = 'Series updated successfully.';
                    }
                } else {
                    $seriesId = $this->db->insert('multimedia_series', $data);
                    $_SESSION['flash_success'] = ($pubFields['status'] === 'pending') ? 'Series submitted for review successfully.' : 'Series created successfully.';
                }

                $genres = (array)$request->post('genres', []);
                Genre::syncForContent('series', $seriesId, $genres);

                return Response::redirect($isModerator ? '/admin/page/multimedia-series' : '/admin/page/multimedia-my-submissions');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                $series = ($id > 0) ? Series::find($id) : null;
                $fallback = $isModerator ? '/admin/page/multimedia-series' : '/admin/page/multimedia-my-submissions';
                if (!$series || !MultimediaPermission::canDeleteContent($series, $user)) {
                    return $this->deleteDeniedResponse($request, 'You cannot delete this series.', $fallback);
                }
                if ($id > 0) {
                    $episodes = $this->db->select("SELECT id FROM multimedia_episodes WHERE series_id = ?", [$id]);
                    foreach ($episodes as $ep) {
                        $this->db->delete('multimedia_sources', ['content_type' => 'episode', 'content_id' => $ep->id]);
                        $this->db->delete('multimedia_subtitles', ['content_type' => 'episode', 'content_id' => $ep->id]);
                        PlaybackProgress::deleteForContent('episode', (int)$ep->id);
                        Favorite::deleteForContent('episode', (int)$ep->id);
                        MultimediaEngagementService::deleteForContent('episode', (int)$ep->id);
                        MediaLocalization::deleteForContent('episode', (int)$ep->id);
                    }
                    $this->db->delete('multimedia_episodes', ['series_id' => $id]);
                    $this->db->delete('multimedia_seasons', ['series_id' => $id]);
                    $this->db->delete('multimedia_content_genres', ['content_type' => 'series', 'content_id' => $id]);
                    $this->db->delete('multimedia_series', ['id' => $id]);
                    Favorite::deleteForContent('series', $id);
                    MultimediaEngagementService::deleteForContent('series', $id);
                    MultimediaSubscriptionService::deleteForTarget('series', $id);
                    MediaLocalization::deleteForContent('series', $id);
                }
                return $this->deleteSuccessResponse($request, 'Series deleted successfully.', $fallback);
            }
        }

        if ($request->get('new') && !$isModerator && !MultimediaPermission::canUserSubmit('series', $user)) {
            $_SESSION['flash_error'] = 'Series submissions are currently disabled.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }

        $editId = (int)$request->get('edit', 0);
        $editSeries = ($editId > 0) ? Series::find($editId) : null;
        if ($editSeries && !$isModerator && !MultimediaPermission::canEditContent($editSeries, $user)) {
            $_SESSION['flash_error'] = 'You do not have permission to edit this multimedia item.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }
        $items = $isModerator ? Series::all() : Series::forUser((int)$user->id);
        $genres = Genre::all();

        return $this->renderView('series', [
            'items'      => $items,
            'editSeries' => $editSeries,
            'genres'     => $genres,
        ]);
    }

    // 4. Seasons CRUD
    public function seasons(Request $request): Response|string
    {
        $user = $this->currentUser();
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $seriesId = (int)$request->post('series_id', 0);
                $data = [
                    'series_id'     => $seriesId,
                    'season_number' => (int)$request->post('season_number', 1),
                    'title'         => trim((string)$request->post('title', '')) ?: 'Season ' . (int)$request->post('season_number', 1),
                    'description'   => trim((string)$request->post('description', '')),
                    'poster'        => trim((string)$request->post('poster', '')),
                    'release_date'  => trim((string)$request->post('release_date', '')) ?: null,
                    'sort_order'    => (int)$request->post('sort_order', 0),
                ];

                if ($id > 0) {
                    $this->db->update('multimedia_seasons', $data, ['id' => $id]);
                    $_SESSION['flash_success'] = 'Season updated successfully.';
                } else {
                    $this->db->insert('multimedia_seasons', $data);
                    $_SESSION['flash_success'] = 'Season created successfully.';
                }
                return Response::redirect('/admin/page/multimedia-seasons?series_id=' . $seriesId);
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                $seriesId = (int)$request->post('series_id', 0);
                $season = ($id > 0) ? Season::find($id) : null;
                $parentSeries = $season ? $season->getSeries() : ($seriesId > 0 ? Series::find($seriesId) : null);
                $fallback = '/admin/page/multimedia-seasons' . ($seriesId > 0 ? '?series_id=' . $seriesId : '');
                if (!$parentSeries || !MultimediaPermission::canDeleteContent($parentSeries, $user)) {
                    return $this->deleteDeniedResponse($request, 'You cannot delete this season.', $fallback);
                }
                if ($id > 0) {
                    $episodes = $this->db->select("SELECT id FROM multimedia_episodes WHERE season_id = ?", [$id]);
                    foreach ($episodes as $ep) {
                        $this->db->delete('multimedia_sources', ['content_type' => 'episode', 'content_id' => $ep->id]);
                        $this->db->delete('multimedia_subtitles', ['content_type' => 'episode', 'content_id' => $ep->id]);
                    }
                    $this->db->delete('multimedia_episodes', ['season_id' => $id]);
                    $this->db->delete('multimedia_seasons', ['id' => $id]);
                }
                return $this->deleteSuccessResponse($request, 'Season deleted successfully.', $fallback);
            }
        }

        $seriesList = Series::all();
        $selectedSeriesId = (int)$request->get('series_id', ($seriesList[0]->id ?? 0));
        $items = $this->db->select("SELECT * FROM multimedia_seasons WHERE series_id = ? ORDER BY season_number ASC", [$selectedSeriesId]);
        $seasons = array_map(fn($r) => new Season((array)$r), $items);

        $editId = (int)$request->get('edit', 0);
        $editSeason = ($editId > 0) ? Season::find($editId) : null;

        return $this->renderView('seasons', [
            'seriesList'       => $seriesList,
            'selectedSeriesId' => $selectedSeriesId,
            'seasons'          => $seasons,
            'editSeason'       => $editSeason,
        ]);
    }

    // 5. Episodes CRUD
    public function episodes(Request $request): Response|string
    {
        $user = $this->currentUser();
        $isModerator = MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
        $canPublish = MultimediaPermission::can(MultimediaPermission::PUBLISH, $user);
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $seasonId = (int)$request->post('season_id', 0);
                $seriesId = (int)$request->post('series_id', 0);
                $existing = ($id > 0) ? Episode::find($id) : null;

                if ($id > 0) {
                    if (!$existing || !MultimediaPermission::canEditContent($existing, $user)) {
                        return Response::make('<h1>403 Forbidden - You cannot edit this episode</h1>', 403);
                    }
                } else {
                    if (!MultimediaPermission::canUserSubmit('episode', $user)) {
                        return Response::make('<h1>403 Forbidden - Episode submissions disabled for your role</h1>', 403);
                    }
                }

                if ($seriesId <= 0 && $existing && !empty($existing->series_id)) {
                    $seriesId = (int)$existing->series_id;
                }
                if ($seriesId <= 0 && $seasonId > 0) {
                    $season = Season::find($seasonId);
                    if ($season && !empty($season->series_id)) {
                        $seriesId = (int)$season->series_id;
                    }
                }

                // Verify parent series ownership for non-moderators
                if (!$isModerator) {
                    $parentSeries = $seriesId > 0 ? Series::find($seriesId) : null;
                    if (!$parentSeries || (int)$parentSeries->user_id !== (int)($user ? $user->id : 0)) {
                        return Response::make('<h1>403 Forbidden - You can only create or edit episodes for your own series</h1>', 403);
                    }
                }

                $title = trim((string)$request->post('title', ''));
                $slug = trim((string)$request->post('slug', '')) ?: str_slug($title . '-s' . $seasonId . '-e' . (int)$request->post('episode_number', 1));
                $pubFields = $this->resolvePublishingFields($request, 'multimedia_episodes', $id);

                $wasPublished = $existing && $existing->status === 'published';
                $ownerId = ($id > 0 && $existing) ? ((int)($existing->user_id ?? ($user ? $user->id : 1)) ?: (int)($user ? $user->id : 1)) : (int)($user ? $user->id : 1);

                $data = [
                    'user_id'         => $ownerId,
                    'series_id'       => $seriesId,
                    'season_id'       => $seasonId,
                    'episode_number'  => (int)$request->post('episode_number', 1),
                    'title'           => $title,
                    'slug'            => $slug,
                    'description'     => trim((string)$request->post('description', '')),
                    'thumbnail'       => trim((string)$request->post('thumbnail', '')),
                    'duration'        => (int)$request->post('duration', 0),
                    'release_date'    => trim((string)$request->post('release_date', '')) ?: null,
                    'status'          => ($id > 0) ? ($existing->status ?? 'draft') : 'draft', // Order A5: evaluate after source persistence
                    'publish_at'      => $pubFields['publish_at'],
                    'published_at'    => $pubFields['published_at'],
                    'unpublish_at'    => $pubFields['unpublish_at'],
                    'access_mode'     => (string)$request->post('access_mode', 'inherit'),
                    'download_policy' => (string)$request->post('download_policy', 'inherit'),
                    'download_url'    => trim((string)$request->post('download_url', '')) ?: null,
                    'sort_order'      => (int)$request->post('sort_order', 0),
                ];

                if ($id > 0) {
                    $this->db->update('multimedia_episodes', $data, ['id' => $id]);
                    $epId = $id;
                } else {
                    $epId = (int)$this->db->insert('multimedia_episodes', $data);
                }

                // Handle thumbnail file upload
                $thumbUploaded = $this->handleImageUpload($request, 'thumbnail_file', 'episode', $epId, 'thumbnails');
                if ($thumbUploaded !== null) {
                    $this->db->update('multimedia_episodes', ['thumbnail' => $thumbUploaded], ['id' => $epId]);
                }

                // Handle video source upload or URL (Order A5: step 4)
                $sourceResult = $this->handleMediaSourceUploadOrUrl($request, 'episode', $epId);

                // Handle subtitle track upload
                $this->handleSubtitleUpload($request, 'episode', $epId);

                // Reload source list from database (Order A5: step 5)
                $currentSources = MediaSource::getForContent('episode', $epId, false);
                $hasSources = !empty($currentSources);

                // Evaluate readiness and process Publish Now / Pending
                if ($pubFields['status'] === 'published') {
                    if ($hasSources) {
                        $this->db->update('multimedia_episodes', [
                            'status'           => 'published',
                            'published_at'     => $pubFields['published_at'] ?? gmdate('Y-m-d H:i:s'),
                            'approved_by'      => $user->id,
                            'approved_at'      => gmdate('Y-m-d H:i:s'),
                            'rejected_by'      => null,
                            'rejected_at'      => null,
                            'rejection_reason' => null,
                        ], ['id' => $epId]);
                        if (!$wasPublished) {
                            MultimediaNotificationService::onEpisodePublished($epId);
                        }
                        $_SESSION['flash_success'] = ($id > 0) ? 'Episode updated successfully.' : 'Episode created successfully.';
                        unset($_SESSION['flash_error'], $_SESSION['flash_warning']);
                    } else {
                        $this->db->update('multimedia_episodes', ['status' => 'draft'], ['id' => $epId]);
                        unset($_SESSION['flash_success']);
                        $exactReason = !empty($_SESSION['flash_error']) ? $_SESSION['flash_error'] : 'no video source is attached';
                        $_SESSION['flash_error'] = 'Notice: Episode was saved as Draft because no playable video source could be attached (' . $exactReason . ').';
                        $_SESSION['flash_warning'] = 'Notice: Episode was saved as Draft because no video source is attached (' . $exactReason . ').';
                    }
                } elseif ($pubFields['status'] === 'pending') {
                    $this->db->update('multimedia_episodes', [
                        'status'           => 'pending',
                        'approved_by'      => null,
                        'approved_at'      => null,
                        'rejected_by'      => null,
                        'rejected_at'      => null,
                        'rejection_reason' => null,
                    ], ['id' => $epId]);
                    if (!empty($pubFields['was_published'])) {
                        $_SESSION['flash_warning'] = 'Content updated. Because you are not a moderator, your edit returned this episode to Pending Review and it will remain unpublished until re-approved.';
                        $_SESSION['flash_success'] = 'Your changes were submitted for review.';
                    } else {
                        $_SESSION['flash_success'] = 'Episode submitted for review successfully.';
                    }
                } else {
                    $this->db->update('multimedia_episodes', ['status' => $pubFields['status']], ['id' => $epId]);
                    $_SESSION['flash_success'] = ($id > 0) ? 'Episode updated successfully.' : 'Episode saved successfully.';
                }

                // Handle multiple download sources
                $this->handleDownloadSources($request, 'episode', $epId);

                return Response::redirect($isModerator ? '/admin/page/multimedia-episodes?season_id=' . $seasonId : '/admin/page/multimedia-my-submissions');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                $seasonId = (int)$request->post('season_id', 0);
                $ep = ($id > 0) ? Episode::find($id) : null;
                $fallback = $isModerator ? ('/admin/page/multimedia-episodes' . ($seasonId > 0 ? '?season_id=' . $seasonId : '')) : '/admin/page/multimedia-my-submissions';
                if (!$ep || !MultimediaPermission::canDeleteContent($ep, $user)) {
                    return $this->deleteDeniedResponse($request, 'You cannot delete this episode.', $fallback);
                }
                if ($id > 0) {
                    $this->db->delete('multimedia_episodes', ['id' => $id]);
                    $this->db->delete('multimedia_sources', ['content_type' => 'episode', 'content_id' => $id]);
                    $this->db->delete('multimedia_subtitles', ['content_type' => 'episode', 'content_id' => $id]);
                    try { $this->db->delete('multimedia_download_sources', ['content_type' => 'episode', 'content_id' => $id]); } catch (\Throwable) {}
                    PlaybackProgress::deleteForContent('episode', $id);
                    Favorite::deleteForContent('episode', $id);
                    MultimediaEngagementService::deleteForContent('episode', $id);
                }
                return $this->deleteSuccessResponse($request, 'Episode deleted successfully.', $fallback);
            }
        }

        if ($request->get('new') && !$isModerator && !MultimediaPermission::canUserSubmit('episode', $user)) {
            $_SESSION['flash_error'] = 'Episode submissions are currently disabled.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }

        $seriesList = $isModerator ? Series::all() : Series::forUser((int)$user->id);
        $selectedSeasonId = (int)$request->get('season_id', 0);
        if ($isModerator) {
            $seasons = Season::all();
        } else {
            $seriesIds = array_map(fn($s) => (int)$s->id, $seriesList);
            if (!empty($seriesIds)) {
                $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
                $rows = $this->db->select("SELECT * FROM multimedia_seasons WHERE series_id IN ({$placeholders}) ORDER BY season_number ASC", $seriesIds);
                $seasons = array_map(fn($r) => new Season((array)$r), $rows);
            } else {
                $seasons = [];
            }
        }

        $whereClauses = [];
        $params = [];
        if ($selectedSeasonId > 0) {
            $whereClauses[] = "season_id = ?";
            $params[] = $selectedSeasonId;
        }
        if (!$isModerator) {
            $whereClauses[] = "user_id = ?";
            $params[] = (int)$user->id;
        }
        $whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";
        $items = $this->db->select("SELECT * FROM multimedia_episodes {$whereSql} ORDER BY sort_order ASC, episode_number ASC", $params);
        $episodes = array_map(fn($r) => new Episode((array)$r), $items);

        $editId = (int)$request->get('edit', 0);
        $editEpisode = ($editId > 0) ? Episode::find($editId) : null;
        if ($editEpisode && !$isModerator && !MultimediaPermission::canEditContent($editEpisode, $user)) {
            $_SESSION['flash_error'] = 'You do not have permission to edit this multimedia item.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }

        $mediaStatuses = [];
        $defaultSources = [];
        foreach ($episodes as $ep) {
            $mediaStatuses[$ep->id] = $this->getMediaStatusForContent('episode', (int)$ep->id);
            $defaultSources[$ep->id] = MediaSource::getDefault('episode', (int)$ep->id);
        }

        if ($editEpisode && !isset($mediaStatuses[$editEpisode->id])) {
            $mediaStatuses[$editEpisode->id] = $this->getMediaStatusForContent('episode', (int)$editEpisode->id);
            $defaultSources[$editEpisode->id] = MediaSource::getDefault('episode', (int)$editEpisode->id);
        }

        $uploadMax = ini_get('upload_max_filesize') ?: '2M';
        $postMax = ini_get('post_max_size') ?: '8M';
        $ffmpegActive = false;
        try {
            $ffmpegActive = class_exists(FFmpegService::class) && FFmpegService::isAvailable();
        } catch (\Throwable) {
            $ffmpegActive = false;
        }

        return $this->renderView('episodes', [
            'seriesList'       => $seriesList,
            'seasons'          => $seasons,
            'selectedSeasonId' => $selectedSeasonId,
            'episodes'         => $episodes,
            'editEpisode'      => $editEpisode,
            'mediaStatuses'    => $mediaStatuses,
            'defaultSources'   => $defaultSources,
            'attachedSources'  => $editEpisode ? MediaSource::getForContent('episode', (int)$editEpisode->id, false) : [],
            'downloadSources'  => $editEpisode ? DownloadSource::getForContent('episode', (int)$editEpisode->id, false) : [],
            'uploadMax'        => $uploadMax,
            'postMax'          => $postMax,
            'ffmpegActive'     => $ffmpegActive,
        ]);
    }

    // 6. Songs CRUD
    public function songs(Request $request): Response|string
    {
        $user = $this->currentUser();
        $isModerator = MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
        $canPublish = MultimediaPermission::can(MultimediaPermission::PUBLISH, $user);
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $existing = ($id > 0) ? Song::find($id) : null;

                if ($id > 0) {
                    if (!$existing || !MultimediaPermission::canEditContent($existing, $user)) {
                        return Response::make('<h1>403 Forbidden - You cannot edit this song</h1>', 403);
                    }
                } else {
                    if (!MultimediaPermission::canUserSubmit('song', $user)) {
                        return Response::make('<h1>403 Forbidden - Song submissions disabled for your role</h1>', 403);
                    }
                }

                $albumId = (int)$request->post('album_id', 0);
                if ($albumId > 0 && !$isModerator) {
                    $album = Album::find($albumId);
                    if (!$album || (int)$album->user_id !== (int)($user ? $user->id : 0)) {
                        return Response::make('<h1>403 Forbidden - You can only attach songs to your own albums</h1>', 403);
                    }
                }

                $title = trim((string)$request->post('title', ''));
                $slug = trim((string)$request->post('slug', '')) ?: str_slug($title);
                $pubFields = $this->resolvePublishingFields($request, 'multimedia_songs', $id);

                $playbackType = (string)$request->post('playback_type', 'audio');
                if (!in_array($playbackType, [Song::PLAYBACK_AUDIO, Song::PLAYBACK_VIDEO, Song::PLAYBACK_AUDIO_VIDEO], true)) {
                    $playbackType = Song::PLAYBACK_AUDIO;
                }
                $defaultPlaybackMode = (string)$request->post('default_playback_mode', 'audio');
                if (!in_array($defaultPlaybackMode, ['audio', 'video'], true)) {
                    $defaultPlaybackMode = 'audio';
                }

                $wasPublished = $existing && $existing->status === 'published';
                $ownerId = ($id > 0 && $existing) ? ((int)($existing->user_id ?? ($user ? $user->id : 1)) ?: (int)($user ? $user->id : 1)) : (int)($user ? $user->id : 1);

                $data = [
                    'user_id'               => $ownerId,
                    'title'                 => $title,
                    'slug'                  => $slug,
                    'description'           => trim((string)$request->post('description', '')),
                    'cover'                 => trim((string)$request->post('cover', '')),
                    'artist_id'             => (int)$request->post('artist_id', 0) ?: null,
                    'album_id'              => $albumId ?: null,
                    'language'              => trim((string)$request->post('language', '')),
                    'release_date'          => trim((string)$request->post('release_date', '')) ?: null,
                    'duration'              => (int)$request->post('duration', 0),
                    'lyrics'                => trim((string)$request->post('lyrics', '')),
                    'featured'              => (int)$request->post('featured', 0),
                    'playback_type'         => $playbackType,
                    'default_playback_mode' => $defaultPlaybackMode,
                    'status'                => ($id > 0) ? ($existing->status ?? 'draft') : 'draft', // Order A5: evaluate after source persistence
                    'publish_at'            => $pubFields['publish_at'],
                    'published_at'          => $pubFields['published_at'],
                    'unpublish_at'          => $pubFields['unpublish_at'],
                    'access_mode'           => (string)$request->post('access_mode', 'public'),
                    'download_policy'       => (string)$request->post('download_policy', 'inherit'),
                    'download_url'          => trim((string)$request->post('download_url', '')) ?: null,
                    'seo_title'             => trim((string)$request->post('seo_title', '')),
                    'seo_description'       => trim((string)$request->post('seo_description', '')),
                ];

                if ($id > 0) {
                    $this->db->update('multimedia_songs', $data, ['id' => $id]);
                    $songId = $id;
                } else {
                    $songId = (int)$this->db->insert('multimedia_songs', $data);
                }

                // Handle cover file upload if provided
                $coverUploaded = $this->handleImageUpload($request, 'cover_file', 'song', $songId, 'covers');
                if ($coverUploaded !== null) {
                    $this->db->update('multimedia_songs', ['cover' => $coverUploaded], ['id' => $songId]);
                }

                // Handle unified media upload or URL (Order A5: step 4)
                $sourceResult = $this->handleMediaSourceUploadOrUrl($request, 'song', $songId);

                // Reload sources from database and evaluate readiness (Order A5: steps 5-8)
                if ($pubFields['status'] === 'published') {
                    $audioSources = MediaSource::getForContent('song', $songId, false, 'audio');
                    $videoSources = MediaSource::getForContent('song', $songId, false, 'video');
                    $hasAudio = !empty($audioSources);
                    $hasVideo = !empty($videoSources);

                    $isReady = match ($playbackType) {
                        Song::PLAYBACK_AUDIO       => $hasAudio,
                        Song::PLAYBACK_VIDEO       => $hasVideo,
                        Song::PLAYBACK_AUDIO_VIDEO => ($hasAudio && $hasVideo),
                        default                    => ($hasAudio || $hasVideo),
                    };

                    if ($isReady) {
                        $this->db->update('multimedia_songs', [
                            'status'           => 'published',
                            'published_at'     => $pubFields['published_at'] ?? gmdate('Y-m-d H:i:s'),
                            'approved_by'      => $user->id,
                            'approved_at'      => gmdate('Y-m-d H:i:s'),
                            'rejected_by'      => null,
                            'rejected_at'      => null,
                            'rejection_reason' => null,
                        ], ['id' => $songId]);
                        if (!$wasPublished) {
                            MultimediaNotificationService::onSongPublished((int)$songId);
                        }
                        $_SESSION['flash_success'] = ($id > 0) ? 'Song updated and published successfully.' : 'Song created and published successfully.';
                        unset($_SESSION['flash_error'], $_SESSION['flash_warning']);
                    } else {
                        $this->db->update('multimedia_songs', ['status' => 'draft'], ['id' => $songId]);
                        unset($_SESSION['flash_success']);
                        $msg = match ($playbackType) {
                            Song::PLAYBACK_AUDIO       => 'Song saved as Draft because no audio source is attached. Add an audio file or URL to publish.',
                            Song::PLAYBACK_VIDEO       => 'Song saved as Draft because no video source is attached. Add a video file, stream, or embed to publish.',
                            Song::PLAYBACK_AUDIO_VIDEO => 'Song saved as Draft because Dual-Mode requires both an audio source AND a video source. Add both media types to publish.',
                            default                    => 'Song saved as Draft because no playable media source is attached.',
                        };
                        $_SESSION['flash_warning'] = 'Notice: ' . $msg;
                        $exactReason = !empty($_SESSION['flash_error']) ? $_SESSION['flash_error'] : $msg;
                        $_SESSION['flash_error'] = 'Media source could not be saved: ' . $exactReason . '. Song saved as Draft.';
                    }
                } elseif ($pubFields['status'] === 'pending') {
                    $this->db->update('multimedia_songs', [
                        'status'           => 'pending',
                        'approved_by'      => null,
                        'approved_at'      => null,
                        'rejected_by'      => null,
                        'rejected_at'      => null,
                        'rejection_reason' => null,
                    ], ['id' => $songId]);
                    if (!empty($pubFields['was_published'])) {
                        $_SESSION['flash_warning'] = 'Content updated. Because you are not a moderator, your edit returned this song to Pending Review and it will remain unpublished until re-approved.';
                        $_SESSION['flash_success'] = 'Your changes were submitted for review.';
                    } else {
                        $_SESSION['flash_success'] = 'Song submitted for review successfully.';
                    }
                } else {
                    $this->db->update('multimedia_songs', ['status' => $pubFields['status']], ['id' => $songId]);
                    $_SESSION['flash_success'] = ($id > 0) ? 'Song updated successfully.' : 'Song saved successfully.';
                }

                $genres = (array)$request->post('genres', []);
                Genre::syncForContent('song', $songId, $genres);

                // Handle multiple download sources
                $this->handleDownloadSources($request, 'song', $songId);

                return Response::redirect($isModerator ? '/admin/page/multimedia-songs' : '/admin/page/multimedia-my-submissions');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                $song = ($id > 0) ? Song::find($id) : null;
                $fallback = $isModerator ? '/admin/page/multimedia-songs' : '/admin/page/multimedia-my-submissions';
                if (!$song || !MultimediaPermission::canDeleteContent($song, $user)) {
                    return $this->deleteDeniedResponse($request, 'You cannot delete this song.', $fallback);
                }
                if ($id > 0) {
                    $this->db->delete('multimedia_songs', ['id' => $id]);
                    $this->db->delete('multimedia_sources', ['content_type' => 'song', 'content_id' => $id]);
                    try { $this->db->delete('multimedia_download_sources', ['content_type' => 'song', 'content_id' => $id]); } catch (\Throwable) {}
                    $this->db->delete('multimedia_playlist_items', ['song_id' => $id]);
                    $this->db->delete('multimedia_content_genres', ['content_type' => 'song', 'content_id' => $id]);
                    PlaybackProgress::deleteForContent('song', $id);
                    Favorite::deleteForContent('song', $id);
                    MultimediaEngagementService::deleteForContent('song', $id);
                }
                return $this->deleteSuccessResponse($request, 'Song deleted successfully.', $fallback);
            }

            if ($action === 'bulk') {
                $bulkAction = trim((string)$request->post('bulk_action', ''));
                $rawIds = (array)$request->post('ids', []);
                $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($id) => $id > 0)));

                if (empty($ids)) {
                    $_SESSION['flash_error'] = 'No songs were selected for bulk action.';
                    return Response::redirect('/admin/page/multimedia-songs');
                }

                if ($bulkAction === 'delete') {
                    $deleted = 0;
                    foreach ($ids as $id) {
                        $s = Song::find($id);
                        if (!$s || !MultimediaPermission::canDeleteContent($s, $user)) {
                            continue;
                        }
                        $this->db->delete('multimedia_songs', ['id' => $id]);
                        $this->db->delete('multimedia_sources', ['content_type' => 'song', 'content_id' => $id]);
                        try { $this->db->delete('multimedia_download_sources', ['content_type' => 'song', 'content_id' => $id]); } catch (\Throwable) {}
                        $this->db->delete('multimedia_playlist_items', ['song_id' => $id]);
                        $this->db->delete('multimedia_content_genres', ['content_type' => 'song', 'content_id' => $id]);
                        PlaybackProgress::deleteForContent('song', $id);
                        Favorite::deleteForContent('song', $id);
                        MultimediaEngagementService::deleteForContent('song', $id);
                        $deleted++;
                    }
                    $_SESSION['flash_success'] = "{$deleted} song(s) deleted successfully.";
                } elseif ($bulkAction === 'publish') {
                    if (!$canPublish) {
                        return Response::make('<h1>403 Forbidden - Publishing requires moderator or admin role</h1>', 403);
                    }
                    $published = 0;
                    $drafted = 0;
                    foreach ($ids as $id) {
                        $song = Song::find($id);
                        if (!$song) continue;
                        $playbackType = $song->getPlaybackType();
                        $audioSources = MediaSource::getForContent('song', $id, false, 'audio');
                        $videoSources = MediaSource::getForContent('song', $id, false, 'video');
                        $hasAudio = !empty($audioSources);
                        $hasVideo = !empty($videoSources);

                        $isReady = match ($playbackType) {
                            Song::PLAYBACK_AUDIO       => $hasAudio,
                            Song::PLAYBACK_VIDEO       => $hasVideo,
                            Song::PLAYBACK_AUDIO_VIDEO => ($hasAudio && $hasVideo),
                            default                    => ($hasAudio || $hasVideo),
                        };

                        if ($isReady) {
                            $this->db->update('multimedia_songs', [
                                'status'           => 'published',
                                'published_at'     => gmdate('Y-m-d H:i:s'),
                                'approved_by'      => $user->id,
                                'approved_at'      => gmdate('Y-m-d H:i:s'),
                                'rejected_by'      => null,
                                'rejected_at'      => null,
                                'rejection_reason' => null,
                            ], ['id' => $id]);
                            if ($song->status !== 'published') {
                                MultimediaNotificationService::onSongPublished((int)$id);
                            }
                            $published++;
                        } else {
                            $this->db->update('multimedia_songs', ['status' => 'draft'], ['id' => $id]);
                            $drafted++;
                        }
                    }
                    if ($drafted > 0) {
                        $_SESSION['flash_warning'] = "{$published} song(s) published. {$drafted} song(s) kept as Draft because required media sources are not attached.";
                    } else {
                        $_SESSION['flash_success'] = "{$published} song(s) published successfully.";
                    }
                } elseif ($bulkAction === 'draft') {
                    if (!MultimediaPermission::can(MultimediaPermission::CREATE) && !MultimediaPermission::can(MultimediaPermission::EDIT)) {
                        return Response::make('<h1>403 Forbidden</h1>', 403);
                    }
                    $updated = 0;
                    foreach ($ids as $id) {
                        $s = Song::find($id);
                        if (!$s || !MultimediaPermission::canEditContent($s, $user)) {
                            continue;
                        }
                        $this->db->update('multimedia_songs', ['status' => 'draft'], ['id' => $id]);
                        $updated++;
                    }
                    $_SESSION['flash_success'] = "{$updated} song(s) moved to Draft.";
                } else {
                    $_SESSION['flash_error'] = 'Invalid bulk action specified.';
                }

                return Response::redirect('/admin/page/multimedia-songs');
            }
        }

        if ($request->get('new') && !$isModerator && !MultimediaPermission::canUserSubmit('song', $user)) {
            $_SESSION['flash_error'] = 'Song submissions are currently disabled.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }

        $editId = (int)$request->get('edit', 0);
        $editSong = ($editId > 0) ? Song::find($editId) : null;
        if ($editSong && !$isModerator && !MultimediaPermission::canEditContent($editSong, $user)) {
            $_SESSION['flash_error'] = 'You do not have permission to edit this multimedia item.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }
        $items = $isModerator ? Song::all() : Song::forUser((int)$user->id);
        $artists = Artist::all();
        $albums = $isModerator ? Album::all() : Album::forUser((int)$user->id);
        $genres = Genre::all();

        $mediaStatuses = [];
        $defaultSources = [];
        $defaultAudioSources = [];
        $defaultVideoSources = [];
        foreach ($items as $s) {
            $mediaStatuses[$s->id] = $this->getMediaStatusForContent('song', (int)$s->id);
            $defaultSources[$s->id] = MediaSource::getDefault('song', (int)$s->id);
            $defaultAudioSources[$s->id] = MediaSource::getDefault('song', (int)$s->id, true, 'audio');
            $defaultVideoSources[$s->id] = MediaSource::getDefault('song', (int)$s->id, true, 'video');
        }

        if ($editSong && !isset($mediaStatuses[$editSong->id])) {
            $mediaStatuses[$editSong->id] = $this->getMediaStatusForContent('song', (int)$editSong->id);
            $defaultSources[$editSong->id] = MediaSource::getDefault('song', (int)$editSong->id);
            $defaultAudioSources[$editSong->id] = MediaSource::getDefault('song', (int)$editSong->id, true, 'audio');
            $defaultVideoSources[$editSong->id] = MediaSource::getDefault('song', (int)$editSong->id, true, 'video');
        }

        $attachedAudioSources = $editSong ? MediaSource::getForContent('song', (int)$editSong->id, false, 'audio') : [];
        $attachedVideoSources = $editSong ? MediaSource::getForContent('song', (int)$editSong->id, false, 'video') : [];
        $attachedSources = $editSong ? MediaSource::getForContent('song', (int)$editSong->id, false) : [];

        $uploadMax = ini_get('upload_max_filesize') ?: '2M';
        $postMax = ini_get('post_max_size') ?: '8M';
        $ffmpegActive = false;
        try {
            $ffmpegActive = class_exists(FFmpegService::class) && FFmpegService::isAvailable();
        } catch (\Throwable) {
            $ffmpegActive = false;
        }

        return $this->renderView('songs', [
            'items'                => $items,
            'editSong'             => $editSong,
            'artists'              => $artists,
            'albums'               => $albums,
            'genres'               => $genres,
            'mediaStatuses'        => $mediaStatuses,
            'defaultSources'       => $defaultSources,
            'defaultAudioSources'  => $defaultAudioSources,
            'defaultVideoSources'  => $defaultVideoSources,
            'attachedAudioSources' => $attachedAudioSources,
            'attachedVideoSources' => $attachedVideoSources,
            'attachedSources'      => $attachedSources,
            'downloadSources'      => $editSong ? DownloadSource::getForContent('song', (int)$editSong->id, false) : [],
            'uploadMax'            => $uploadMax,
            'postMax'              => $postMax,
            'ffmpegActive'         => $ffmpegActive,
        ]);
    }

    // 7. Playlists CRUD
    public function playlists(Request $request): Response|string
    {
        $user = $this->currentUser();
        $isModerator = MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
        $canPublish = MultimediaPermission::can(MultimediaPermission::PUBLISH, $user);
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $editPlaylist = ($id > 0) ? Playlist::find($id) : null;
                if ($id > 0) {
                    if (!$editPlaylist || !MultimediaPermission::canEditContent($editPlaylist, $user)) {
                        return Response::make('<h1>403 Forbidden - You cannot edit this playlist</h1>', 403);
                    }
                } else {
                    if (!MultimediaPermission::canUserSubmit('playlist', $user)) {
                        return Response::make('<h1>403 Forbidden - Playlist creation disabled for your role</h1>', 403);
                    }
                }

                $title = trim((string)$request->post('title', ''));
                $slug = trim((string)$request->post('slug', '')) ?: str_slug($title);
                $accessMode = (string)$request->post('access_mode', 'public');
                $requestedStatus = (string)$request->post('status', 'published');
                if ($accessMode === 'private') {
                    $status = $requestedStatus;
                } else {
                    $status = $canPublish ? $requestedStatus : ($requestedStatus === 'draft' ? 'draft' : 'pending');
                }

                $ownerId = ($id > 0 && $editPlaylist) ? ((int)($editPlaylist->user_id ?? ($user ? $user->id : 1)) ?: (int)($user ? $user->id : 1)) : (int)($user ? $user->id : 1);

                $data = [
                    'user_id'         => $ownerId,
                    'title'           => $title,
                    'slug'            => $slug,
                    'description'     => trim((string)$request->post('description', '')),
                    'cover'           => trim((string)$request->post('cover', '')),
                    'featured'        => (int)$request->post('featured', 0),
                    'status'          => $status,
                    'access_mode'     => $accessMode,
                    'download_policy' => (string)$request->post('download_policy', 'inherit'),
                ];

                if ($status === 'published') {
                    $data['approved_by'] = $user->id;
                    $data['approved_at'] = gmdate('Y-m-d H:i:s');
                    $data['rejected_by'] = null;
                    $data['rejected_at'] = null;
                    $data['rejection_reason'] = null;
                } elseif ($status === 'pending') {
                    $data['approved_by'] = null;
                    $data['approved_at'] = null;
                    $data['rejected_by'] = null;
                    $data['rejected_at'] = null;
                    $data['rejection_reason'] = null;
                }

                if ($id > 0) {
                    $this->db->update('multimedia_playlists', $data, ['id' => $id]);
                    $playlistId = $id;
                    if ($editPlaylist && ($editPlaylist->status === 'published') && !$canPublish) {
                        $_SESSION['flash_warning'] = 'Content updated. Because you are not a moderator, your edit returned this playlist to Pending Review and it will remain unpublished until re-approved.';
                        $_SESSION['flash_success'] = 'Your changes were submitted for review.';
                    } else {
                        $_SESSION['flash_success'] = 'Playlist updated successfully.';
                    }
                } else {
                    $playlistId = $this->db->insert('multimedia_playlists', $data);
                    $_SESSION['flash_success'] = ($status === 'pending') ? 'Playlist submitted for review successfully.' : 'Playlist created successfully.';
                }

                // Selected songs
                $songIds = (array)$request->post('songs', []);
                $playlist = Playlist::find($playlistId);
                if ($playlist) {
                    $this->db->delete('multimedia_playlist_items', ['playlist_id' => $playlistId]);
                    foreach ($songIds as $order => $sId) {
                        $playlist->addSong((int)$sId, (int)$order);
                    }
                    if ($data['status'] === 'published') {
                        MultimediaNotificationService::onPlaylistUpdated((int)$playlistId);
                    }
                }

                return Response::redirect($isModerator ? '/admin/page/multimedia-playlists' : '/admin/page/multimedia-my-submissions');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                $pl = ($id > 0) ? Playlist::find($id) : null;
                $fallback = $isModerator ? '/admin/page/multimedia-playlists' : '/admin/page/multimedia-my-submissions';
                if (!$pl || !MultimediaPermission::canDeleteContent($pl, $user)) {
                    return $this->deleteDeniedResponse($request, 'You cannot delete this playlist.', $fallback);
                }
                if ($id > 0) {
                    $this->db->delete('multimedia_playlists', ['id' => $id]);
                    $this->db->delete('multimedia_playlist_items', ['playlist_id' => $id]);
                    Favorite::deleteForContent('playlist', $id);
                    MultimediaEngagementService::deleteForContent('playlist', $id);
                    MultimediaSubscriptionService::deleteForTarget('playlist', $id);
                }
                return $this->deleteSuccessResponse($request, 'Playlist deleted successfully.', $fallback);
            }
        }

        if ($request->get('new') && !$isModerator && !MultimediaPermission::canUserSubmit('playlist', $user)) {
            $_SESSION['flash_error'] = 'Playlist creation is currently disabled.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }

        $editId = (int)$request->get('edit', 0);
        $editPlaylist = ($editId > 0) ? Playlist::find($editId) : null;
        if ($editPlaylist && !$isModerator && !MultimediaPermission::canEditContent($editPlaylist, $user)) {
            $_SESSION['flash_error'] = 'You do not have permission to edit this multimedia item.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }
        $items = $isModerator ? Playlist::all() : Playlist::forUser((int)$user->id);
        $allSongs = $isModerator ? Song::all() : Song::forUser((int)$user->id);

        return $this->renderView('playlists', [
            'items'        => $items,
            'editPlaylist' => $editPlaylist,
            'allSongs'     => $allSongs,
            'isCreating'   => (bool)$request->get('new'),
        ]);
    }

    // 8. Genres CRUD
    public function genres(Request $request): Response|string
    {
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $name = trim((string)$request->post('name', ''));
                $slug = trim((string)$request->post('slug', '')) ?: str_slug($name);
                $data = [
                    'name'        => $name,
                    'slug'        => $slug,
                    'description' => trim((string)$request->post('description', '')),
                ];

                if ($id > 0) {
                    $this->db->update('multimedia_genres', $data, ['id' => $id]);
                    $_SESSION['flash_success'] = 'Genre updated.';
                } else {
                    $this->db->insert('multimedia_genres', $data);
                    $_SESSION['flash_success'] = 'Genre created.';
                }
                return Response::redirect('/admin/page/multimedia-genres');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                if ($id > 0) {
                    $this->db->delete('multimedia_genres', ['id' => $id]);
                    $this->db->delete('multimedia_content_genres', ['genre_id' => $id]);
                    $_SESSION['flash_success'] = 'Genre deleted.';
                }
                return Response::redirect('/admin/page/multimedia-genres');
            }
        }

        $items = Genre::all();
        $editId = (int)$request->get('edit', 0);
        $editGenre = ($editId > 0) ? Genre::find($editId) : null;

        return $this->renderView('genres', [
            'items'     => $items,
            'editGenre' => $editGenre,
        ]);
    }

    // 9. Artists CRUD
    public function artists(Request $request): Response|string
    {
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $name = trim((string)$request->post('name', ''));
                $slug = trim((string)$request->post('slug', '')) ?: str_slug($name);
                $data = [
                    'name'      => $name,
                    'slug'      => $slug,
                    'photo'     => trim((string)$request->post('photo', '')),
                    'biography' => trim((string)$request->post('biography', '')),
                    'status'    => (string)$request->post('status', 'active'),
                ];

                if ($id > 0) {
                    $this->db->update('multimedia_artists', $data, ['id' => $id]);
                    $_SESSION['flash_success'] = 'Artist updated.';
                } else {
                    $this->db->insert('multimedia_artists', $data);
                    $_SESSION['flash_success'] = 'Artist created.';
                }
                return Response::redirect('/admin/page/multimedia-artists');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                if ($id > 0) {
                    $this->db->delete('multimedia_artists', ['id' => $id]);
                    MultimediaSubscriptionService::deleteForTarget('artist', $id);
                    $_SESSION['flash_success'] = 'Artist deleted.';
                }
                return Response::redirect('/admin/page/multimedia-artists');
            }
        }

        $items = Artist::all();
        $editId = (int)$request->get('edit', 0);
        $editArtist = ($editId > 0) ? Artist::find($editId) : null;

        return $this->renderView('artists', [
            'items'      => $items,
            'editArtist' => $editArtist,
        ]);
    }

    // 10. Albums CRUD
    public function albums(Request $request): Response|string
    {
        $user = $this->currentUser();
        $isModerator = MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
        $canPublish = MultimediaPermission::can(MultimediaPermission::PUBLISH, $user);
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $editAlbum = ($id > 0) ? Album::find($id) : null;
                if ($id > 0) {
                    if (!$editAlbum || !MultimediaPermission::canEditContent($editAlbum, $user)) {
                        return Response::make('<h1>403 Forbidden - You cannot edit this album</h1>', 403);
                    }
                } else {
                    if (!MultimediaPermission::canUserSubmit('album', $user)) {
                        return Response::make('<h1>403 Forbidden - Album submissions disabled for your role</h1>', 403);
                    }
                }

                $title = trim((string)$request->post('title', ''));
                $slug = trim((string)$request->post('slug', '')) ?: str_slug($title);
                $pubFields = $this->resolvePublishingFields($request, 'multimedia_albums', $id);
                $ownerId = ($id > 0 && $editAlbum) ? ((int)($editAlbum->user_id ?? ($user ? $user->id : 1)) ?: (int)($user ? $user->id : 1)) : (int)($user ? $user->id : 1);

                $data = [
                    'user_id'      => $ownerId,
                    'title'        => $title,
                    'slug'         => $slug,
                    'cover'        => trim((string)$request->post('cover', '')),
                    'artist_id'    => (int)$request->post('artist_id', 0) ?: null,
                    'release_date' => trim((string)$request->post('release_date', '')) ?: null,
                    'status'       => $pubFields['status'],
                ];

                if ($pubFields['status'] === 'published') {
                    $data['approved_by'] = $user->id;
                    $data['approved_at'] = gmdate('Y-m-d H:i:s');
                    $data['rejected_by'] = null;
                    $data['rejected_at'] = null;
                    $data['rejection_reason'] = null;
                } elseif ($pubFields['status'] === 'pending') {
                    $data['approved_by'] = null;
                    $data['approved_at'] = null;
                    $data['rejected_by'] = null;
                    $data['rejected_at'] = null;
                    $data['rejection_reason'] = null;
                }

                if ($id > 0) {
                    $this->db->update('multimedia_albums', $data, ['id' => $id]);
                    if (!empty($pubFields['was_published']) && !$canPublish) {
                        $_SESSION['flash_warning'] = 'Content updated. Because you are not a moderator, your edit returned this album to Pending Review and it will remain unpublished until re-approved.';
                        $_SESSION['flash_success'] = 'Your changes were submitted for review.';
                    } else {
                        $_SESSION['flash_success'] = 'Album updated.';
                    }
                } else {
                    $this->db->insert('multimedia_albums', $data);
                    $_SESSION['flash_success'] = ($pubFields['status'] === 'pending')
                        ? 'Album submitted successfully and is pending moderator review.'
                        : 'Album created.';
                }
                return Response::redirect($isModerator ? '/admin/page/multimedia-albums' : '/admin/page/multimedia-my-submissions');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                $editAlbum = ($id > 0) ? Album::find($id) : null;
                $fallback = $isModerator ? '/admin/page/multimedia-albums' : '/admin/page/multimedia-my-submissions';
                if (!$editAlbum || !MultimediaPermission::canDeleteContent($editAlbum, $user)) {
                    return $this->deleteDeniedResponse($request, 'You cannot delete this album.', $fallback);
                }
                if ($id > 0) {
                    $this->db->delete('multimedia_albums', ['id' => $id]);
                }
                return $this->deleteSuccessResponse($request, 'Album deleted.', $fallback);
            }
        }

        if ($request->get('new') && !$isModerator && !MultimediaPermission::canUserSubmit('album', $user)) {
            $_SESSION['flash_error'] = 'Album submissions are currently disabled.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }

        $editId = (int)$request->get('edit', 0);
        $editAlbum = ($editId > 0) ? Album::find($editId) : null;
        if ($editAlbum && !$isModerator && !MultimediaPermission::canEditContent($editAlbum, $user)) {
            $_SESSION['flash_error'] = 'You do not have permission to edit this multimedia item.';
            return Response::redirect('/admin/page/multimedia-my-submissions');
        }

        $items = $isModerator ? Album::all() : Album::forUser((int)$user->id);
        $artists = Artist::all();

        return $this->renderView('albums', [
            'items'     => $items,
            'artists'   => $artists,
            'editAlbum' => $editAlbum,
        ]);
    }

    // 11. Sources CRUD
    public function sources(Request $request): Response|string
    {
        $user = $this->currentUser();
        $isModerator = MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
        $action = (string)$request->post('action', $request->get('action', 'index'));

        $checkParentOwnership = function(string $ct, int $cid) use ($user, $isModerator): bool {
            if ($isModerator) {
                return true;
            }
            if ($cid <= 0) {
                return false;
            }
            $parent = match ($ct) {
                'movie'   => Movie::find($cid),
                'episode' => Episode::find($cid),
                'song'    => Song::find($cid),
                default   => null,
            };
            return $parent !== null && MultimediaPermission::canEditContent($parent, $user);
        };

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            $redirectTo = (string)$request->post('redirect_to', '');

            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $url = trim((string)$request->post('url_or_path', $request->post('video_url', $request->post('media_url', ''))));
                $manualType = trim((string)$request->post('source_type', ''));

                $rawLang = trim((string)$request->post('language_code', ''));
                $langCode = $rawLang !== '' ? MediaLanguageService::normalizeLanguageCode($rawLang) : null;
                $audioRole = trim((string)$request->post('audio_role', 'main')) ?: 'main';

                $contentType = (string)$request->post('content_type', 'movie');
                $contentId = (int)$request->post('content_id', 0);
                $isDefault = (int)$request->post('is_default', 1);
                $mediaKind = (string)$request->post('media_kind', '');

                if (!$checkParentOwnership($contentType, $contentId)) {
                    return Response::make('<h1>403 Forbidden - You cannot manage sources for this content</h1>', 403);
                }

                $saveResult = MediaSourceService::saveSource([
                    'id'             => $id,
                    'content_type'   => $contentType,
                    'content_id'     => $contentId,
                    'media_kind'     => $mediaKind,
                    'source_type'    => ($manualType !== 'unknown' && $manualType !== '') ? $manualType : null,
                    'url_or_path'    => $url,
                    'label'          => trim((string)$request->post('label', 'Default')) ?: 'Default',
                    'quality'        => trim((string)$request->post('quality', '')),
                    'poster'         => trim((string)$request->post('poster', '')),
                    'language_code'  => $langCode,
                    'audio_role'     => $audioRole,
                    'is_default'     => $isDefault,
                    'allow_download' => (string)$request->post('allow_download', 'inherit'),
                    'status'         => (string)$request->post('status', 'active'),
                    'sort_order'     => (int)$request->post('sort_order', 0),
                ]);

                if (!$saveResult['success']) {
                    $_SESSION['flash_error'] = $saveResult['error'];
                } else {
                    $_SESSION['flash_success'] = ($id > 0) ? 'Media source updated.' : 'Media source created.';
                }

                if ($redirectTo !== '' && str_starts_with($redirectTo, '/admin/')) {
                    return Response::redirect($redirectTo);
                }
                $retParams = "content_type={$contentType}&content_id={$contentId}";
                return Response::redirect('/admin/page/multimedia-sources?' . $retParams);
            }

            if ($action === 'delete') {
                $id = (int)($request->post('id') ?: $request->post('source_id', 0));
                $ct = (string)$request->post('content_type', 'movie');
                $cid = (int)$request->post('content_id', 0);
                if ($id > 0) {
                    $source = MediaSource::find($id);
                    if ($source) {
                        if (!$checkParentOwnership((string)$source->content_type, (int)$source->content_id)) {
                            return Response::make('<h1>403 Forbidden - You cannot manage sources for this content</h1>', 403);
                        }
                        $kind = $source->getMediaKind();
                        $this->db->delete('multimedia_sources', ['id' => $id]);
                        MediaSource::promoteNextDefault($ct, $cid, $kind);
                        $_SESSION['flash_success'] = 'Media source deleted.';
                    }
                }
                if ($redirectTo !== '' && str_starts_with($redirectTo, '/admin/')) {
                    return Response::redirect($redirectTo);
                }
                return Response::redirect("/admin/page/multimedia-sources?content_type={$ct}&content_id={$cid}");
            }

            if ($action === 'toggle_status') {
                $id = (int)($request->post('id') ?: $request->post('source_id', 0));
                $source = MediaSource::find($id);
                if ($source) {
                    if (!$checkParentOwnership((string)$source->content_type, (int)$source->content_id)) {
                        return Response::make('<h1>403 Forbidden - You cannot manage sources for this content</h1>', 403);
                    }
                    $newStatus = ($source->status === 'active') ? 'inactive' : 'active';
                    $this->db->update('multimedia_sources', ['status' => $newStatus], ['id' => $id]);
                    if ($newStatus === 'inactive' && !empty($source->is_default)) {
                        $this->db->update('multimedia_sources', ['is_default' => 0], ['id' => $id]);
                        MediaSource::promoteNextDefault((string)$source->content_type, (int)$source->content_id, $source->getMediaKind());
                    }
                    $_SESSION['flash_success'] = "Media source is now {$newStatus}.";
                }
                if ($redirectTo !== '' && str_starts_with($redirectTo, '/admin/')) {
                    return Response::redirect($redirectTo);
                }
                return Response::redirect('/admin/page/multimedia-sources');
            }

            if ($action === 'set_default') {
                $id = (int)($request->post('id') ?: $request->post('source_id', 0));
                $source = MediaSource::find($id);
                if ($source) {
                    if (!$checkParentOwnership((string)$source->content_type, (int)$source->content_id)) {
                        return Response::make('<h1>403 Forbidden - You cannot manage sources for this content</h1>', 403);
                    }
                    $this->db->update('multimedia_sources', ['status' => 'active', 'is_default' => 1], ['id' => $id]);
                    MediaSource::enforceSingleDefault((string)$source->content_type, (int)$source->content_id, $id, $source->getMediaKind());
                    $_SESSION['flash_success'] = 'Default playback stream updated.';
                }
                if ($redirectTo !== '' && str_starts_with($redirectTo, '/admin/')) {
                    return Response::redirect($redirectTo);
                }
                return Response::redirect('/admin/page/multimedia-sources');
            }

            if ($action === 'reorder') {
                $id = (int)($request->post('id') ?: $request->post('source_id', 0));
                $direction = (string)$request->post('direction', 'up');
                $source = MediaSource::find($id);
                if ($source) {
                    if (!$checkParentOwnership((string)$source->content_type, (int)$source->content_id)) {
                        return Response::make('<h1>403 Forbidden - You cannot manage sources for this content</h1>', 403);
                    }
                    $ct = (string)$source->content_type;
                    $cid = (int)$source->content_id;
                    $all = MediaSource::getForContent($ct, $cid, false);
                    $currentIndex = null;
                    foreach ($all as $idx => $s) {
                        if ((int)$s->id === $id) {
                            $currentIndex = $idx;
                            break;
                        }
                    }
                    if ($currentIndex !== null) {
                        $targetIndex = ($direction === 'up') ? $currentIndex - 1 : $currentIndex + 1;
                        if (isset($all[$targetIndex])) {
                            $targetSource = $all[$targetIndex];
                            $curOrder = (int)$source->sort_order;
                            $tgtOrder = (int)$targetSource->sort_order;
                            if ($curOrder === $tgtOrder) {
                                $curOrder = $currentIndex;
                                $tgtOrder = $targetIndex;
                            }
                            $this->db->update('multimedia_sources', ['sort_order' => $tgtOrder], ['id' => $source->id]);
                            $this->db->update('multimedia_sources', ['sort_order' => $curOrder], ['id' => $targetSource->id]);
                            $_SESSION['flash_success'] = 'Playback source order updated.';
                        }
                    }
                }
                if ($redirectTo !== '' && str_starts_with($redirectTo, '/admin/')) {
                    return Response::redirect($redirectTo);
                }
                return Response::redirect('/admin/page/multimedia-sources');
            }

            if ($action === 'add_source') {
                $contentType = (string)$request->post('content_type', 'movie');
                $contentId = (int)$request->post('content_id', 0);

                if (!$checkParentOwnership($contentType, $contentId)) {
                    return Response::make('<h1>403 Forbidden - You cannot manage sources for this content</h1>', 403);
                }

                $label = trim((string)$request->post('source_label', $request->post('label', '')));
                $url = trim((string)$request->post('video_url', $request->post('audio_url', $request->post('url_or_path', $request->post('url', '')))));
                $url = MediaSourceResolver::extractIframeUrl($url);
                $manualType = trim((string)$request->post('source_type', ''));
                $isDefault = (int)$request->post('is_default', 0);
                $targetKind = (string)$request->post('media_kind', '');
                $file = $request->file('video_file') ?? ($request->file('audio_file') ?? ($_FILES['video_file'] ?? ($_FILES['audio_file'] ?? null)));

                if ($file && is_array($file) && !empty($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($file['size'] ?? 0) > 0) {
                    $category = ($targetKind === 'audio' || $contentType === 'song') ? 'audio' : 'video';
                    $secValidation = UploadSecurityService::validate($file, $category);
                    if (!$secValidation['valid']) {
                        $_SESSION['flash_error'] = $secValidation['error'];
                        if ($redirectTo !== '' && str_starts_with($redirectTo, '/admin/')) {
                            return Response::redirect($redirectTo);
                        }
                        return Response::redirect("/admin/page/multimedia-sources?content_type={$contentType}&content_id={$contentId}");
                    }

                    $origName = $secValidation['sanitized_name'];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                    $isAudioUpload = ($category === 'audio');
                    $mediaKind = $isAudioUpload ? 'audio' : 'video';
                    $sourceType = $isAudioUpload ? 'audio' : (($ext === 'webm') ? 'webm' : 'video');
                    $defaultLabel = $isAudioUpload ? 'Local Audio' : 'Local Video';

                    $disk = MediaStorageManager::getDisk();
                    $key = MediaStorageManager::buildKey($contentType, $contentId, 'original', $origName);
                    $stream = @fopen($file['tmp_name'], 'rb');
                    if ($stream) {
                        $mime = $secValidation['mime'] ?: ($file['type'] ?? ($isAudioUpload ? 'audio/mpeg' : 'video/mp4'));
                        $disk->put($key, $stream, ['mime_type' => $mime]);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        $storedUrl = $disk->url($key);
                        $maxOrderRow = $this->db->selectOne("SELECT MAX(sort_order) as m FROM multimedia_sources WHERE content_type = ? AND content_id = ?", [$contentType, $contentId]);
                        $nextOrder = (int)($maxOrderRow->m ?? 0) + 1;

                        $saveResult = MediaSourceService::saveSource([
                            'content_type'   => $contentType,
                            'content_id'     => $contentId,
                            'media_kind'     => $mediaKind,
                            'source_mode'    => 'local',
                            'source_type'    => $sourceType,
                            'url_or_path'    => $storedUrl,
                            'label'          => $label ?: $defaultLabel,
                            'quality'        => 'original',
                            'mime_type'      => $mime,
                            'is_default'     => $isDefault,
                            'status'         => 'active',
                            'sort_order'     => $nextOrder,
                        ]);

                        if ($saveResult['success']) {
                            $_SESSION['flash_success'] = 'Additional media source uploaded successfully.';
                        } else {
                            $_SESSION['flash_error'] = $saveResult['error'];
                        }
                    }
                } elseif ($url !== '') {
                    $mediaKind = $targetKind ?: (($contentType === 'song') ? 'video' : 'video');
                    $maxOrderRow = $this->db->selectOne("SELECT MAX(sort_order) as m FROM multimedia_sources WHERE content_type = ? AND content_id = ?", [$contentType, $contentId]);
                    $nextOrder = (int)($maxOrderRow->m ?? 0) + 1;

                    $saveResult = MediaSourceService::saveSource([
                        'content_type'   => $contentType,
                        'content_id'     => $contentId,
                        'media_kind'     => $mediaKind,
                        'source_type'    => ($manualType !== '' && $manualType !== 'auto') ? $manualType : null,
                        'url_or_path'    => $url,
                        'label'          => $label,
                        'is_default'     => $isDefault,
                        'status'         => 'active',
                        'sort_order'     => $nextOrder,
                    ]);

                    if ($saveResult['success']) {
                        $_SESSION['flash_success'] = 'Additional media source attached successfully.';
                    } else {
                        $_SESSION['flash_error'] = 'Could not save source: ' . ($saveResult['error'] ?? 'Validation failed');
                    }
                }
                if ($redirectTo !== '' && str_starts_with($redirectTo, '/admin/')) {
                    return Response::redirect($redirectTo);
                }
                return Response::redirect("/admin/page/multimedia-sources?content_type={$contentType}&content_id={$contentId}");
            }
        }

        $contentType = (string)$request->get('content_type', 'movie');
        $contentId = (int)$request->get('content_id', 0);
        $editId = (int)$request->get('edit', 0);
        $editSource = ($editId > 0) ? MediaSource::find($editId) : null;

        if ($contentType && $contentId > 0 && !$checkParentOwnership($contentType, $contentId)) {
            return Response::make('<h1>403 Forbidden - Access denied to sources</h1>', 403);
        }
        if ($editSource && !$checkParentOwnership((string)$editSource->content_type, (int)$editSource->content_id)) {
            return Response::make('<h1>403 Forbidden - Access denied to this source</h1>', 403);
        }

        $items = $isModerator ? MediaSource::all() : ($contentId > 0 ? MediaSource::getForContent($contentType, $contentId, false) : []);
        $movies = $isModerator ? Movie::all() : Movie::forUser((int)$user->id);
        $episodes = $isModerator ? Episode::all() : Episode::forUser((int)$user->id);
        $songs = $isModerator ? Song::all() : Song::forUser((int)$user->id);

        return $this->renderView('sources', [
            'items'       => $items,
            'contentType' => $contentType,
            'contentId'   => $contentId,
            'editSource'  => $editSource,
            'movies'      => $movies,
            'episodes'    => $episodes,
            'songs'       => $songs,
        ]);
    }

    // 12. Subtitles CRUD
    public function subtitles(Request $request): Response|string
    {
        $user = $this->currentUser();
        $isModerator = MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
        $action = (string)$request->post('action', $request->get('action', 'index'));

        $checkParentOwnership = function(string $ct, int $cid) use ($user, $isModerator): bool {
            if ($isModerator) {
                return true;
            }
            if ($cid <= 0) {
                return false;
            }
            $parent = match ($ct) {
                'movie'   => Movie::find($cid),
                'episode' => Episode::find($cid),
                default   => null,
            };
            return $parent !== null && MultimediaPermission::canEditContent($parent, $user);
        };

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $contentType = (string)$request->post('content_type', 'movie');
                $contentId = (int)$request->post('content_id', 0);

                if (!$checkParentOwnership($contentType, $contentId)) {
                    return Response::make('<h1>403 Forbidden - You cannot manage subtitles for this content</h1>', 403);
                }

                $rawLang = trim((string)$request->post('language_code', $request->post('language', 'en')));
                $langCode = MediaLanguageService::normalizeLanguageCode($rawLang ?: 'en');
                $label = trim((string)$request->post('label', '')) ?: MediaLanguageService::getLanguageLabel($langCode);
                $isDef = (int)$request->post('is_default', 0);
                $isForced = (int)$request->post('is_forced', 0);
                $isSdh = (int)$request->post('is_sdh', 0);

                $data = [
                    'content_type'  => $contentType,
                    'content_id'    => $contentId,
                    'language'      => $langCode,
                    'language_code' => $langCode,
                    'label'         => $label,
                    'file_or_url'   => trim((string)$request->post('file_or_url', '')),
                    'format'        => (string)$request->post('format', 'vtt'),
                    'is_default'    => $isDef,
                    'is_forced'     => $isForced,
                    'is_sdh'        => $isSdh,
                    'sort_order'    => (int)$request->post('sort_order', 0),
                ];

                if ($id > 0) {
                    $this->db->update('multimedia_subtitles', $data, ['id' => $id]);
                    $subId = $id;
                    $_SESSION['flash_success'] = 'Subtitle updated.';
                } else {
                    $subId = (int)$this->db->insert('multimedia_subtitles', $data);
                    $_SESSION['flash_success'] = 'Subtitle created.';
                }

                if ($isDef === 1) {
                    Subtitle::enforceSingleDefault($contentType, $contentId, $subId);
                }

                return Response::redirect('/admin/page/multimedia-subtitles');
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                if ($id > 0) {
                    $sub = Subtitle::find($id);
                    if ($sub) {
                        if (!$checkParentOwnership((string)$sub->content_type, (int)$sub->content_id)) {
                            return Response::make('<h1>403 Forbidden - You cannot delete subtitles for this content</h1>', 403);
                        }
                        $this->db->delete('multimedia_subtitles', ['id' => $id]);
                        $_SESSION['flash_success'] = 'Subtitle deleted.';
                    }
                }
                return Response::redirect('/admin/page/multimedia-subtitles');
            }
        }

        $editId = (int)$request->get('edit', 0);
        $editSubtitle = ($editId > 0) ? Subtitle::find($editId) : null;
        if ($editSubtitle && !$checkParentOwnership((string)$editSubtitle->content_type, (int)$editSubtitle->content_id)) {
            return Response::make('<h1>403 Forbidden - Access denied to this subtitle</h1>', 403);
        }

        $movies = $isModerator ? Movie::all() : Movie::forUser((int)$user->id);
        $episodes = $isModerator ? Episode::all() : Episode::forUser((int)$user->id);

        if ($isModerator) {
            $items = Subtitle::all();
        } else {
            $userMovieIds = array_map(fn($m) => (int)$m->id, $movies);
            $userEpisodeIds = array_map(fn($e) => (int)$e->id, $episodes);
            $subtitles = Subtitle::all();
            $items = array_values(array_filter($subtitles, function($s) use ($userMovieIds, $userEpisodeIds) {
                if ($s->content_type === 'movie' && in_array((int)$s->content_id, $userMovieIds, true)) return true;
                if ($s->content_type === 'episode' && in_array((int)$s->content_id, $userEpisodeIds, true)) return true;
                return false;
            }));
        }

        return $this->renderView('subtitles', [
            'items'        => $items,
            'movies'       => $movies,
            'episodes'     => $episodes,
            'editSubtitle' => $editSubtitle,
        ]);
    }

    // 13. Access Control overview
    public function accessRules(Request $request): string
    {
        return $this->renderView('access', [
            'digitalAvailable' => \FavoriteCMS\Multimedia\Integrations\FavoriteDigitalAdapter::isAvailable(),
            'payAvailable'     => \FavoriteCMS\Multimedia\Integrations\FavoritePayAdapter::isAvailable(),
        ]);
    }

    // 14. Analytics
    public function analytics(Request $request): Response|string
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::VIEW_ANALYTICS, $user)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to view multimedia analytics.</p>', 403);
        }

        $isFullAnalytics = ($user->hasRole('super-admin') || $user->hasRole('admin') || $user->hasRole('editor') || $user->hasRole('moderator'));
        $authorId = $isFullAnalytics ? null : (int)$user->id;

        $range       = (string)$request->get('range', 'last_30_days');
        $customStart = (string)$request->get('start_date', '');
        $customEnd   = (string)$request->get('end_date', '');
        $dateFilter  = MultimediaAnalyticsService::resolveDateRange($range, $customStart, $customEnd);

        // CSV Export Workflow
        if ($request->get('export') === 'csv') {
            $reportType  = (string)$request->get('report', 'overview');
            $contentType = (string)$request->get('content_type', 'movie');
            $csvData     = MultimediaAnalyticsService::exportCsv($reportType, $dateFilter, $contentType);
            $filename    = "multimedia_report_{$reportType}_" . gmdate('Ymd_His') . ".csv";

            return Response::make($csvData, 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control'       => 'no-store, private',
            ]);
        }

        $tab         = (string)$request->get('tab', 'overview');
        $contentType = (string)$request->get('content_type', 'movie');
        $sortBy      = (string)$request->get('sort', 'plays');
        $sortOrder   = (string)$request->get('order', 'DESC');
        $page        = max(1, (int)$request->get('p', 1));
        $perPage     = 20;
        $offset      = ($page - 1) * $perPage;

        // Data based on selected tab
        $overviewStats    = MultimediaAnalyticsService::getOverviewStats($dateFilter, $authorId);
        $trends           = [];
        $contentData      = [];
        $dropOff          = [];
        $resumeStats      = [];
        $engagementStats  = [];
        $followerGrowth   = [];
        $notificationData = [];
        $discoveryData    = [];
        $premiumFunnel    = [];
        $languageStats    = [];
        $operationalStats = [];

        if ($tab === 'overview') {
            $trends     = MultimediaAnalyticsService::getPlayTrends($dateFilter);
            $dropOff    = MultimediaAnalyticsService::getCompletionAndDropOff('movie', null, $dateFilter);
            $contentData = MultimediaAnalyticsService::getContentPerformance('movie', $dateFilter, 5, 0, 'plays', 'DESC', 'en', $authorId);
        } elseif ($tab === 'content') {
            $contentData = MultimediaAnalyticsService::getContentPerformance($contentType, $dateFilter, $perPage, $offset, $sortBy, $sortOrder, 'en', $authorId);
            $dropOff     = MultimediaAnalyticsService::getCompletionAndDropOff($contentType, null, $dateFilter);
        } elseif ($tab === 'engagement') {
            $engagementStats  = MultimediaAnalyticsService::getRatingAndReviewAnalytics($dateFilter);
            $followerGrowth   = MultimediaAnalyticsService::getFollowerGrowth($dateFilter);
            $notificationData = MultimediaAnalyticsService::getNotificationAnalytics($dateFilter);
            $resumeStats      = MultimediaAnalyticsService::getResumeEngagement($dateFilter);
        } elseif ($tab === 'funnel') {
            $premiumFunnel = MultimediaAnalyticsService::getPremiumFunnel($dateFilter);
            $languageStats = MultimediaAnalyticsService::getLanguageUsageAnalytics($dateFilter);
            $discoveryData = MultimediaAnalyticsService::getDiscoveryPerformance($dateFilter);
        } elseif ($tab === 'operations') {
            $operationalStats = MultimediaAnalyticsService::getProcessingAndStorageMetrics();
        }

        if ($authorId !== null) {
            $recentEvents = $this->db->select("
                SELECT ma.* FROM multimedia_analytics ma
                WHERE ma.created_at >= ? AND ma.created_at <= ?
                AND (
                    (ma.content_type = 'movie' AND ma.content_id IN (SELECT id FROM multimedia_movies WHERE user_id = {$authorId}))
                    OR (ma.content_type = 'series' AND ma.content_id IN (SELECT id FROM multimedia_series WHERE user_id = {$authorId}))
                    OR (ma.content_type = 'episode' AND ma.content_id IN (SELECT id FROM multimedia_episodes WHERE user_id = {$authorId}))
                    OR (ma.content_type = 'song' AND ma.content_id IN (SELECT id FROM multimedia_songs WHERE user_id = {$authorId}))
                    OR (ma.content_type = 'playlist' AND ma.content_id IN (SELECT id FROM multimedia_playlists WHERE user_id = {$authorId}))
                )
                ORDER BY ma.id DESC LIMIT 50
            ", [$dateFilter['start'], $dateFilter['end']]);
        } else {
            $recentEvents = $this->db->select("
                SELECT * FROM multimedia_analytics
                WHERE created_at >= ? AND created_at <= ?
                ORDER BY id DESC LIMIT 50
            ", [$dateFilter['start'], $dateFilter['end']]);
        }

        return $this->renderView('analytics', [
            'tab'              => $tab,
            'range'            => $range,
            'dateFilter'       => $dateFilter,
            'contentType'      => $contentType,
            'sortBy'           => $sortBy,
            'sortOrder'        => $sortOrder,
            'page'             => $page,
            'overviewStats'    => $overviewStats,
            'trends'           => $trends,
            'contentData'      => $contentData,
            'dropOff'          => $dropOff,
            'resumeStats'      => $resumeStats,
            'engagementStats'  => $engagementStats,
            'followerGrowth'   => $followerGrowth,
            'notificationData' => $notificationData,
            'discoveryData'    => $discoveryData,
            'premiumFunnel'    => $premiumFunnel,
            'languageStats'    => $languageStats,
            'operationalStats' => $operationalStats,
            'recentEvents'     => $recentEvents,
        ]);
    }

    // 15. Settings
    public function settings(Request $request): Response|string
    {
        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            Setting::set('multimedia', 'enable_downloads', (string)$request->post('enable_downloads', 'yes'));
            Setting::set('multimedia', 'default_video_resolution', (string)$request->post('default_video_resolution', '1080p'));
            Setting::set('multimedia', 'hls_autoplay', (string)$request->post('hls_autoplay', 'no'));
            Setting::set('multimedia', 'auto_play_next', (string)$request->post('auto_play_next', 'yes'));
            Setting::set('multimedia', 'auto_next_countdown', (string)max(3, min(30, (int)$request->post('auto_next_countdown', 5))));
            Setting::set('multimedia', 'player_theme_color', (string)$request->post('player_theme_color', '#2563eb'));
            Setting::set('multimedia', 'enable_discovery', (string)$request->post('enable_discovery', 'yes'));
            Setting::set('multimedia', 'enable_trending', (string)$request->post('enable_trending', 'yes'));
            Setting::set('multimedia', 'trending_window_days', (string)max(1, (int)$request->post('trending_window_days', 7)));
            Setting::set('multimedia', 'max_discovery_items', (string)max(4, (int)$request->post('max_discovery_items', 10)));
            Setting::set('multimedia', 'review_moderation_mode', (string)$request->post('review_moderation_mode', 'auto_approve'));
            Setting::set('multimedia', 'default_media_volume', (string)max(5, min(50, (int)$request->post('default_media_volume', 25))));
            Setting::set('multimedia', 'remember_media_volume', (string)$request->post('remember_media_volume', 'yes'));
            Setting::set('multimedia', 'enable_pip', (string)$request->post('enable_pip', 'yes'));
            Setting::set('multimedia', 'enable_background_audio', (string)$request->post('enable_background_audio', 'yes'));

            // Community Submissions & Moderation Settings
            Setting::set('multimedia', 'user_submissions_enabled', (string)$request->post('user_submissions_enabled', 'yes'));
            Setting::set('multimedia', 'allow_subscriber_submissions', (string)$request->post('allow_subscriber_submissions', 'yes'));
            Setting::set('multimedia', 'allow_contributor_submissions', (string)$request->post('allow_contributor_submissions', 'yes'));
            Setting::set('multimedia', 'allow_author_submissions', (string)$request->post('allow_author_submissions', 'yes'));
            Setting::set('multimedia', 'require_moderation', (string)$request->post('require_moderation', 'yes'));
            Setting::set('multimedia', 'allow_user_movie_upload', (string)$request->post('allow_user_movie_upload', 'yes'));
            Setting::set('multimedia', 'allow_user_series_upload', (string)$request->post('allow_user_series_upload', 'yes'));
            Setting::set('multimedia', 'allow_user_episode_upload', (string)$request->post('allow_user_episode_upload', 'yes'));
            Setting::set('multimedia', 'allow_user_song_upload', (string)$request->post('allow_user_song_upload', 'yes'));
            Setting::set('multimedia', 'allow_user_album_upload', (string)$request->post('allow_user_album_upload', 'yes'));
            Setting::set('multimedia', 'allow_user_playlist_creation', (string)$request->post('allow_user_playlist_creation', 'yes'));
            Setting::set('multimedia', 'max_video_upload_mb', (string)max(1, (int)$request->post('max_video_upload_mb', 500)));
            Setting::set('multimedia', 'max_audio_upload_mb', (string)max(1, (int)$request->post('max_audio_upload_mb', 100)));
            Setting::set('multimedia', 'max_image_upload_mb', (string)max(1, (int)$request->post('max_image_upload_mb', 10)));
            Setting::set('multimedia', 'max_subtitle_upload_mb', (string)max(1, (int)$request->post('max_subtitle_upload_mb', 5)));

            $rawDomains = trim((string)$request->post('trusted_embed_domains', ''));
            $cleanLines = [];
            foreach (preg_split('/[\r\n,;]+/', $rawDomains) as $dLine) {
                $dLine = strtolower(trim($dLine));
                if ($dLine === '') continue;
                $clean = preg_replace('#^https?://#i', '', $dLine);
                $clean = explode('/', $clean)[0];
                $clean = explode(':', $clean)[0];
                $clean = trim($clean);
                if ($clean !== '' && !in_array($clean, $cleanLines, true)) {
                    $cleanLines[] = $clean;
                }
            }
            Setting::set('multimedia', 'trusted_embed_domains', implode("\n", $cleanLines));

            $rawMode = (string)$request->post('embed_sandbox_mode', 'compatible');
            $sandboxMode = in_array($rawMode, ['compatible', 'off-for-trusted-only', 'strict'], true) ? $rawMode : 'compatible';
            Setting::set('multimedia', 'embed_sandbox_mode', $sandboxMode);

            $_SESSION['flash_success'] = 'Settings saved successfully.';
            return Response::redirect('/admin/page/multimedia-settings');
        }

        return $this->renderView('settings', [
            'enableDownloads'       => Setting::get('multimedia', 'enable_downloads', 'yes'),
            'resolution'            => Setting::get('multimedia', 'default_video_resolution', '1080p'),
            'autoPlayNext'          => Setting::get('multimedia', 'auto_play_next', 'yes'),
            'autoNextCountdown'     => (int)Setting::get('multimedia', 'auto_next_countdown', 5),
            'defaultMediaVolume'    => (int)Setting::get('multimedia', 'default_media_volume', 25),
            'rememberMediaVolume'   => Setting::get('multimedia', 'remember_media_volume', 'yes'),
            'enablePip'             => Setting::get('multimedia', 'enable_pip', 'yes'),
            'enableBackgroundAudio' => Setting::get('multimedia', 'enable_background_audio', 'yes'),
            'themeColor'            => Setting::get('multimedia', 'player_theme_color', '#2563eb'),
            'enableDiscovery'       => Setting::get('multimedia', 'enable_discovery', 'yes'),
            'enableTrending'        => Setting::get('multimedia', 'enable_trending', 'yes'),
            'trendingWindowDays'    => (int)Setting::get('multimedia', 'trending_window_days', 7),
            'maxDiscoveryItems'     => (int)Setting::get('multimedia', 'max_discovery_items', 10),
            'reviewModerationMode'  => Setting::get('multimedia', 'review_moderation_mode', 'auto_approve'),
            'trustedEmbedDomains'   => Setting::get('multimedia', 'trusted_embed_domains', ''),
            'embedSandboxMode'      => Setting::get('multimedia', 'embed_sandbox_mode', 'compatible'),
            'userSubmissionsEnabled' => Setting::get('multimedia', 'user_submissions_enabled', 'yes'),
            'allowSubscriberSubmissions' => Setting::get('multimedia', 'allow_subscriber_submissions', 'yes'),
            'allowContributorSubmissions' => Setting::get('multimedia', 'allow_contributor_submissions', 'yes'),
            'allowAuthorSubmissions' => Setting::get('multimedia', 'allow_author_submissions', 'yes'),
            'requireModeration'     => Setting::get('multimedia', 'require_moderation', 'yes'),
            'allowUserMovieUpload'  => Setting::get('multimedia', 'allow_user_movie_upload', 'yes'),
            'allowUserSeriesUpload' => Setting::get('multimedia', 'allow_user_series_upload', 'yes'),
            'allowUserEpisodeUpload'=> Setting::get('multimedia', 'allow_user_episode_upload', 'yes'),
            'allowUserSongUpload'   => Setting::get('multimedia', 'allow_user_song_upload', 'yes'),
            'allowUserAlbumUpload'  => Setting::get('multimedia', 'allow_user_album_upload', 'yes'),
            'allowUserPlaylistCreation' => Setting::get('multimedia', 'allow_user_playlist_creation', 'yes'),
            'maxVideoUploadMb'      => (int)Setting::get('multimedia', 'max_video_upload_mb', 500),
            'maxAudioUploadMb'      => (int)Setting::get('multimedia', 'max_audio_upload_mb', 100),
            'maxImageUploadMb'      => (int)Setting::get('multimedia', 'max_image_upload_mb', 10),
            'maxSubtitleUploadMb'   => (int)Setting::get('multimedia', 'max_subtitle_upload_mb', 5),
        ]);
    }

    // 15b. Theme Studio
    public function themeStudio(Request $request): Response|string
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage Multimedia Theme Studio.</p>', 403);
        }

        if ($request->get('preview') === 'canvas') {
            return $this->themePreview($request);
        }

        $themeManager = ThemeManager::getInstance($this->app);

        if ($request->method() === 'POST') {
            $csrfToken = (string)($request->post('_token') ?? $request->header('X-CSRF-TOKEN') ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            $sessionToken = (string)($_SESSION['_token'] ?? $_SESSION['csrf_token'] ?? '');
            if ($csrfToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $csrfToken)) {
                return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
            }

            $action = (string)$request->post('action', 'save');

            if ($action === 'apply_preset') {
                $presetId = (string)$request->post('preset_id', '');
                if (!ThemePresetRegistry::has($presetId)) {
                    return Response::json(['success' => false, 'error' => 'Preset not found.'], 404);
                }
                $updatedConfig = $themeManager->applyPreset($presetId);
                return Response::json([
                    'success' => true,
                    'message' => "Preset '{$presetId}' applied successfully.",
                    'config' => $updatedConfig->toArray(),
                    'tokens' => (new ThemeTokenResolver($updatedConfig))->toCssVariables(),
                ]);
            }

            if ($action === 'reset_section') {
                $section = (string)$request->post('section', '');
                $updatedConfig = $themeManager->resetSection($section);
                return Response::json([
                    'success' => true,
                    'message' => "Section '{$section}' reset to default.",
                    'config' => $updatedConfig->toArray(),
                    'tokens' => (new ThemeTokenResolver($updatedConfig))->toCssVariables(),
                ]);
            }

            if ($action === 'reset_all') {
                $updatedConfig = $themeManager->resetAll();
                return Response::json([
                    'success' => true,
                    'message' => 'All theme settings reset to default.',
                    'config' => $updatedConfig->toArray(),
                    'tokens' => (new ThemeTokenResolver($updatedConfig))->toCssVariables(),
                ]);
            }

            if ($action === 'preview_draft') {
                $rawConfig = $request->post('theme_config');
                if (is_string($rawConfig)) {
                    $decoded = json_decode($rawConfig, true);
                    $draftData = is_array($decoded) ? $decoded : [];
                } elseif (is_array($rawConfig)) {
                    $draftData = $rawConfig;
                } else {
                    $draftData = [];
                }
                $previewService = new ThemePreviewService();
                $tokens = $previewService->getDraftTokens($draftData);
                return Response::json([
                    'success' => true,
                    'tokens' => $tokens,
                ]);
            }

            // Homepage Builder Actions
            if ($action === 'save_homepage') {
                return $this->apiSaveHomepage($request);
            }
            if ($action === 'reset_homepage') {
                return $this->apiResetHomepage($request);
            }
            if ($action === 'reorder_homepage') {
                return $this->apiReorderHomepage($request);
            }
            if ($action === 'add_section') {
                return $this->apiAddHomepageSection($request);
            }
            if ($action === 'update_section') {
                return $this->apiUpdateHomepageSection($request);
            }
            if ($action === 'delete_section') {
                return $this->apiDeleteHomepageSection($request);
            }
            if ($action === 'duplicate_section') {
                return $this->apiDuplicateHomepageSection($request);
            }
            if ($action === 'preview_homepage') {
                return $this->apiPreviewHomepage($request);
            }

            // Theme Export / Import Actions
            if ($action === 'export_theme') {
                return $this->apiExportTheme($request);
            }
            if ($action === 'import_theme') {
                return $this->apiImportTheme($request);
            }
            if ($action === 'restore_theme_backup') {
                return $this->apiRestoreThemeBackup($request);
            }

            // Save action
            $rawConfig = $request->post('theme_config');
            if (is_string($rawConfig)) {
                $decoded = json_decode($rawConfig, true);
                $saveData = is_array($decoded) ? $decoded : [];
            } elseif (is_array($rawConfig)) {
                $saveData = $rawConfig;
            } else {
                $saveData = $request->all();
                unset($saveData['_token'], $saveData['action']);
            }

            $themeConfig = new ThemeConfig($saveData);
            $errors = $themeConfig->validate();
            if (!empty($errors)) {
                return Response::json([
                    'success' => false,
                    'error' => implode(', ', $errors),
                    'errors' => $errors,
                ], 422);
            }

            $themeManager->saveConfig($themeConfig);

            if (($request->header('Accept') && str_contains($request->header('Accept'), 'application/json')) || $request->isAjax()) {
                return Response::json([
                    'success' => true,
                    'message' => 'Theme settings saved successfully.',
                    'config' => $themeManager->getActiveConfig()->toArray(),
                    'tokens' => $themeManager->getTokenResolver()->toCssVariables(),
                ]);
            }

            $_SESSION['flash_success'] = 'Theme settings saved successfully.';
            return Response::redirect('/admin/page/multimedia-theme');
        }

        $activeConfig = $themeManager->getActiveConfig();
        $presets = ThemePresetRegistry::all();
        $homepageManager = HomepageManager::getInstance($this->app);
        $activeHomepage = $homepageManager->getActiveConfig();

        return $this->renderView('theme/theme-studio', [
            'config'            => $activeConfig,
            'presets'           => $presets,
            'tokenResolver'     => $themeManager->getTokenResolver(),
            'schemaVersion'     => ThemeConfig::SCHEMA_VERSION,
            'csrfToken'         => $_SESSION['_token'] ?? $_SESSION['csrf_token'] ?? '',
            'homepageConfig'    => $activeHomepage,
            'canonicalSections' => SectionRegistry::canonicalDefinitions(),
            'sectionCategories' => SectionRegistry::categories(),
        ]);
    }

    public function themePreview(Request $request): Response|string
    {
        $themeManager = ThemeManager::getInstance($this->app);
        $previewService = new ThemePreviewService();
        $homepageManager = HomepageManager::getInstance($this->app);
        $homepageConfig = $homepageManager->getActiveConfig();

        return $this->renderView('theme/preview-canvas', [
            'config'         => $themeManager->getActiveConfig(),
            'mockData'       => $previewService->getMockData(),
            'tokenResolver'  => $themeManager->getTokenResolver(),
            'homepageConfig' => $homepageConfig,
            'user'           => current_user(),
        ]);
    }

    // Homepage Builder Admin API Endpoints
    public function apiSaveHomepage(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $sections = $data['sections'] ?? $data['homepage_config'] ?? $data;
        if (is_string($sections)) {
            $decoded = json_decode($sections, true);
            $sections = is_array($decoded) ? $decoded : [];
        }
        if (isset($sections['sections']) && is_array($sections['sections'])) {
            $sections = $sections['sections'];
        }

        $config = new HomepageConfig(is_array($sections) ? $sections : []);
        $errors = $config->validate();
        if (!empty($errors)) {
            return Response::json([
                'success' => false,
                'error'   => implode(', ', $errors),
                'errors'  => $errors,
            ], 422);
        }

        $manager = HomepageManager::getInstance($this->app);
        $saved = $manager->saveConfig($config);

        return Response::json([
            'success'  => $saved,
            'message'  => $saved ? 'Homepage configuration saved successfully.' : 'Failed to save configuration.',
            'homepage' => $manager->getActiveConfig()->toArray(),
        ]);
    }

    public function apiResetHomepage(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $manager = HomepageManager::getInstance($this->app);
        $defaultConfig = $manager->resetDefaults();

        return Response::json([
            'success'  => true,
            'message'  => 'Homepage configuration reset to defaults.',
            'homepage' => $defaultConfig->toArray(),
        ]);
    }

    public function apiReorderHomepage(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $orderedIds = (array)($data['order'] ?? $data['ordered_ids'] ?? []);
        $manager = HomepageManager::getInstance($this->app);
        $updated = $manager->reorderSections($orderedIds);

        return Response::json([
            'success'  => true,
            'message'  => 'Homepage sections reordered successfully.',
            'homepage' => $updated->toArray(),
        ]);
    }

    public function apiAddHomepageSection(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $type = (string)($data['type'] ?? '');
        if (!SectionRegistry::isValidType($type)) {
            return Response::json(['success' => false, 'error' => "Unknown section type '{$type}'."], 422);
        }

        $sectionData = $data['section'] ?? $data;
        if (!is_array($sectionData)) {
            $sectionData = ['type' => $type];
        } else {
            $sectionData['type'] = $type;
        }

        $manager = HomepageManager::getInstance($this->app);
        if (count($manager->getActiveConfig()->getSections()) >= HomepageConfig::MAX_SECTIONS) {
            return Response::json(['success' => false, 'error' => 'Maximum limit of ' . HomepageConfig::MAX_SECTIONS . ' sections reached.'], 422);
        }

        $updated = $manager->addSection($sectionData);

        return Response::json([
            'success'  => true,
            'message'  => "Section '{$type}' added successfully.",
            'homepage' => $updated->toArray(),
        ]);
    }

    public function apiUpdateHomepageSection(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $id = (string)($data['id'] ?? '');
        if ($id === '') {
            return Response::json(['success' => false, 'error' => 'Section ID is required.'], 422);
        }

        $sectionUpdates = (array)($data['section'] ?? $data['updates'] ?? $data);
        unset($sectionUpdates['_token'], $sectionUpdates['csrf_token'], $sectionUpdates['action']);

        $manager = HomepageManager::getInstance($this->app);
        $updated = $manager->updateSection($id, $sectionUpdates);

        return Response::json([
            'success'  => true,
            'message'  => "Section updated successfully.",
            'homepage' => $updated->toArray(),
        ]);
    }

    public function apiDeleteHomepageSection(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $id = (string)($data['id'] ?? '');
        if ($id === '') {
            return Response::json(['success' => false, 'error' => 'Section ID is required.'], 422);
        }

        $manager = HomepageManager::getInstance($this->app);
        $updated = $manager->deleteSection($id);

        return Response::json([
            'success'  => true,
            'message'  => "Section deleted successfully.",
            'homepage' => $updated->toArray(),
        ]);
    }

    public function apiDuplicateHomepageSection(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $id = (string)($data['id'] ?? '');
        if ($id === '') {
            return Response::json(['success' => false, 'error' => 'Section ID is required.'], 422);
        }

        $manager = HomepageManager::getInstance($this->app);
        if (count($manager->getActiveConfig()->getSections()) >= HomepageConfig::MAX_SECTIONS) {
            return Response::json(['success' => false, 'error' => 'Maximum limit of ' . HomepageConfig::MAX_SECTIONS . ' sections reached.'], 422);
        }

        $updated = $manager->duplicateSection($id);

        return Response::json([
            'success'  => true,
            'message'  => "Section duplicated successfully.",
            'homepage' => $updated->toArray(),
        ]);
    }

    public function apiPreviewHomepage(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        $sections = $data['sections'] ?? null;
        if (is_string($sections)) {
            $decoded = json_decode($sections, true);
            $sections = is_array($decoded) ? $decoded : null;
        }

        if (is_array($sections)) {
            $config = new HomepageConfig($sections);
        } else {
            $config = HomepageManager::getInstance($this->app)->getActiveConfig();
        }

        $user = $this->currentUser();
        $renderer = new SectionRenderer();
        $html = $renderer->renderAll($config, $user);

        return Response::json([
            'success'  => true,
            'html'     => $html,
            'sections' => $config->getSections(),
        ]);
    }

    public function apiExportTheme(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $service = new ThemeExportImportService();
        $data = $service->export();

        return Response::json($data, 200, [
            'Content-Disposition' => 'attachment; filename="favorite-multimedia-theme-config.json"',
        ]);
    }

    public function apiImportTheme(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $payload = $data['config'] ?? $data['payload'] ?? $data;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            return Response::json(['success' => false, 'error' => 'Invalid or missing configuration JSON.'], 422);
        }

        $service = new ThemeExportImportService();
        $result = $service->import($payload);

        return Response::json($result, $result['success'] ? 200 : 422);
    }

    public function apiRestoreThemeBackup(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $service = new ThemeExportImportService();
        $restored = $service->restoreBackup();

        return Response::json([
            'success' => $restored,
            'message' => $restored ? 'Configuration successfully restored from backup.' : 'No configuration backup available.',
        ], $restored ? 200 : 404);
    }

    // 16. Community Moderation Queue
    public function moderation(Request $request): Response|string
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaEngagementService::canModerate($user)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to moderate multimedia content.</p>', 403);
        }

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            $action = (string)$request->post('action', '');
            $targetType = (string)$request->post('target_type', '');
            $targetId = (int)$request->post('target_id', 0);

            if ($targetType === 'content' && $targetId > 0) {
                $contentType = (string)$request->post('content_type', 'movie');
                $table = match ($contentType) {
                    'movie'    => 'multimedia_movies',
                    'series'   => 'multimedia_series',
                    'episode'  => 'multimedia_episodes',
                    'song'     => 'multimedia_songs',
                    'album'    => 'multimedia_albums',
                    'playlist' => 'multimedia_playlists',
                    default    => null,
                };
                if ($table) {
                    if ($action === 'approve') {
                        $updateData = [
                            'status'           => 'published',
                            'approved_by'      => (int)$user->id,
                            'approved_at'      => date('Y-m-d H:i:s'),
                            'rejected_by'      => null,
                            'rejected_at'      => null,
                            'rejection_reason' => null,
                        ];
                        if (in_array($contentType, ['movie', 'series', 'episode', 'song'], true)) {
                            $existingPub = $this->db->selectOne("SELECT published_at FROM {$table} WHERE id = ?", [$targetId]);
                            if (empty($existingPub?->published_at)) {
                                $updateData['published_at'] = date('Y-m-d H:i:s');
                            }
                        }
                        $this->db->update($table, $updateData, ['id' => $targetId]);
                        $_SESSION['flash_success'] = "Content approved and published successfully.";
                    } elseif ($action === 'reject') {
                        $reason = trim((string)$request->post('rejection_reason', ''));
                        $this->db->update($table, [
                            'status'           => 'rejected',
                            'rejected_by'      => (int)$user->id,
                            'rejected_at'      => date('Y-m-d H:i:s'),
                            'rejection_reason' => $reason !== '' ? $reason : 'Submission does not meet quality guidelines.',
                        ], ['id' => $targetId]);
                        $_SESSION['flash_success'] = "Content submission rejected.";
                    }
                }
                return Response::redirect('/admin/page/multimedia-moderation?tab=pending_content');
            } elseif ($targetType === 'review' && $targetId > 0) {
                if (in_array($action, ['approve', 'reject', 'hide', 'delete'], true)) {
                    MultimediaEngagementService::moderateReview($user, $targetId, $action);
                    $_SESSION['flash_success'] = "Review successfully actioned ({$action}).";
                }
            } elseif ($targetType === 'comment' && $targetId > 0) {
                if (in_array($action, ['approve', 'reject', 'hide', 'delete'], true)) {
                    MultimediaEngagementService::moderateComment($user, $targetId, $action);
                    $_SESSION['flash_success'] = "Comment successfully actioned ({$action}).";
                }
            } elseif ($targetType === 'report' && $targetId > 0) {
                if (in_array($action, ['dismiss', 'action', 'review'], true)) {
                    MultimediaEngagementService::resolveReport($user, $targetId, $action);
                    $_SESSION['flash_success'] = "Report status updated ({$action}).";
                }
            }

            return Response::redirect('/admin/page/multimedia-moderation');
        }

        $tab = (string)$request->get('tab', 'pending_content');
        $page = max(1, (int)$request->get('p', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $items = [];
        $total = 0;

        if ($tab === 'pending_content') {
            $sql = "
                SELECT 'movie' as content_type, id, title, slug, user_id, status, created_at, NULL as rejection_reason FROM multimedia_movies WHERE status = 'pending'
                UNION ALL
                SELECT 'series' as content_type, id, title, slug, user_id, status, created_at, NULL as rejection_reason FROM multimedia_series WHERE status = 'pending'
                UNION ALL
                SELECT 'episode' as content_type, id, title, slug, user_id, status, created_at, NULL as rejection_reason FROM multimedia_episodes WHERE status = 'pending'
                UNION ALL
                SELECT 'song' as content_type, id, title, slug, user_id, status, created_at, NULL as rejection_reason FROM multimedia_songs WHERE status = 'pending'
                UNION ALL
                SELECT 'album' as content_type, id, title, slug, user_id, status, created_at, NULL as rejection_reason FROM multimedia_albums WHERE status = 'pending'
                UNION ALL
                SELECT 'playlist' as content_type, id, title, slug, user_id, status, created_at, NULL as rejection_reason FROM multimedia_playlists WHERE status = 'pending'
                ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}
            ";
            $items = $this->db->select($sql);
            $total = (int)($this->db->selectOne("
                SELECT (
                    (SELECT COUNT(*) FROM multimedia_movies WHERE status = 'pending') +
                    (SELECT COUNT(*) FROM multimedia_series WHERE status = 'pending') +
                    (SELECT COUNT(*) FROM multimedia_episodes WHERE status = 'pending') +
                    (SELECT COUNT(*) FROM multimedia_songs WHERE status = 'pending') +
                    (SELECT COUNT(*) FROM multimedia_albums WHERE status = 'pending') +
                    (SELECT COUNT(*) FROM multimedia_playlists WHERE status = 'pending')
                ) as total
            ")->total ?? 0);
        } elseif ($tab === 'pending_reviews' && MultimediaEngagementService::hasReviewsTable()) {
            $items = $this->db->select("SELECT * FROM multimedia_reviews WHERE status = 'pending' ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
            $total = (int)($this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_reviews WHERE status = 'pending'")->c ?? 0);
        } elseif ($tab === 'reported_reviews' && MultimediaEngagementService::hasReportsTable() && MultimediaEngagementService::hasReviewsTable()) {
            $items = $this->db->select("
                SELECT r.*, rep.reason as report_reason, rep.notes as report_notes, rep.id as report_id
                FROM multimedia_reviews r
                JOIN multimedia_reports rep ON rep.target_type = 'review' AND rep.target_id = r.id
                WHERE rep.status = 'open'
                ORDER BY rep.created_at DESC LIMIT {$perPage} OFFSET {$offset}
            ");
            $total = (int)($this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_reports WHERE target_type = 'review' AND status = 'open'")->c ?? 0);
        } elseif ($tab === 'comments' && MultimediaEngagementService::hasCommentsTable()) {
            $items = $this->db->select("SELECT * FROM multimedia_comments ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
            $total = (int)($this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_comments")->c ?? 0);
        } elseif ($tab === 'reports' && MultimediaEngagementService::hasReportsTable()) {
            $items = $this->db->select("SELECT * FROM multimedia_reports WHERE status = 'open' ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
            $total = (int)($this->db->selectOne("SELECT COUNT(*) as c FROM multimedia_reports WHERE status = 'open'")->c ?? 0);
        }

        $counts = MultimediaEngagementService::getModerationCounts();
        $counts['pending_content'] = (int)($this->db->selectOne("
            SELECT (
                (SELECT COUNT(*) FROM multimedia_movies WHERE status = 'pending') +
                (SELECT COUNT(*) FROM multimedia_series WHERE status = 'pending') +
                (SELECT COUNT(*) FROM multimedia_episodes WHERE status = 'pending') +
                (SELECT COUNT(*) FROM multimedia_songs WHERE status = 'pending') +
                (SELECT COUNT(*) FROM multimedia_albums WHERE status = 'pending') +
                (SELECT COUNT(*) FROM multimedia_playlists WHERE status = 'pending')
            ) as total
        ")->total ?? 0);

        return $this->renderView('moderation', [
            'tab'        => $tab,
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
            'counts'     => $counts,
        ]);
    }

    // 17. Releases & Editorial Calendar
    public function releases(Request $request): Response|string
    {
        $user = $this->currentUser();
        if ($request->method() === 'POST') {
            if (!MultimediaPermission::can(MultimediaPermission::EDIT, $user)) {
                return Response::make('<h1>403 Forbidden</h1>', 403);
            }
            $this->validateCsrf($request);
            $action = (string)$request->post('action', '');

            if ($action === 'publish_now') {
                $type = (string)$request->post('content_type', '');
                $id = (int)$request->post('content_id', 0);
                $res = MultimediaReleaseService::publishNow($type, $id, $user);
                if ($res['success']) {
                    $_SESSION['flash_success'] = "Content successfully published now.";
                } else {
                    $_SESSION['flash_error'] = "Publish failed: " . ($res['error'] ?? 'Unknown error');
                }
                return Response::redirect('/admin/page/multimedia-releases');
            }

            if ($action === 'schedule') {
                $type = (string)$request->post('content_type', '');
                $id = (int)$request->post('content_id', 0);
                $publishAtLocal = (string)$request->post('publish_at', '');
                $unpublishAtLocal = (string)$request->post('unpublish_at', '');
                $publishAtUtc = MultimediaReleaseService::localToUtc($publishAtLocal) ?? $publishAtLocal;
                $unpublishAtUtc = $unpublishAtLocal !== '' ? (MultimediaReleaseService::localToUtc($unpublishAtLocal) ?? $unpublishAtLocal) : null;

                $res = MultimediaReleaseService::scheduleItem($type, $id, $publishAtUtc, $unpublishAtUtc, $user);
                if ($res['success']) {
                    $_SESSION['flash_success'] = "Content successfully scheduled for release.";
                } else {
                    $_SESSION['flash_error'] = "Scheduling failed: " . ($res['error'] ?? 'Unknown error');
                }
                return Response::redirect('/admin/page/multimedia-releases');
            }

            if ($action === 'cancel') {
                $type = (string)$request->post('content_type', '');
                $id = (int)$request->post('content_id', 0);
                $res = MultimediaReleaseService::cancelSchedule($type, $id, $user);
                if ($res['success']) {
                    $_SESSION['flash_success'] = "Schedule cancelled; content reverted to draft.";
                } else {
                    $_SESSION['flash_error'] = "Cancel failed: " . ($res['error'] ?? 'Unknown error');
                }
                return Response::redirect('/admin/page/multimedia-releases');
            }

            if ($action === 'run_due') {
                $res = MultimediaReleaseService::processDueReleases();
                $_SESSION['flash_success'] = sprintf(
                    "Due release processor executed: %d items published, %d unpublished, %d errors.",
                    $res['published'] ?? 0,
                    $res['unpublished'] ?? 0,
                    count($res['errors'] ?? [])
                );
                return Response::redirect('/admin/page/multimedia-releases');
            }
        }

        $typeFilter = (string)$request->get('type', '');
        $statusFilter = (string)$request->get('status', '');
        $month = (string)$request->get('month', date('Y-m'));

        $filters = [];
        if ($typeFilter !== '' && $typeFilter !== 'all') {
            $filters['content_type'] = $typeFilter;
        }
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $filters['status'] = $statusFilter;
        }

        $metrics = MultimediaReleaseService::getOperationalMetrics();
        $queue = MultimediaReleaseService::getScheduledQueue($filters);

        // Calculate start and end UTC for selected calendar month
        $startLocal = $month . '-01 00:00:00';
        $endLocal = date('Y-m-t 23:59:59', strtotime($startLocal));
        $startUtc = MultimediaReleaseService::localToUtc($startLocal) ?? $startLocal;
        $endUtc = MultimediaReleaseService::localToUtc($endLocal) ?? $endLocal;

        $calendarEvents = MultimediaReleaseService::getCalendarReleases($startUtc, $endUtc, $filters);
        $timezone = MultimediaReleaseService::getAppTimezone();

        return $this->renderView('releases', [
            'metrics'        => $metrics,
            'queue'          => $queue,
            'calendarEvents' => $calendarEvents,
            'month'          => $month,
            'typeFilter'     => $typeFilter,
            'statusFilter'   => $statusFilter,
            'timezone'       => $timezone,
        ]);
    }

    // Release API Handlers
    public function apiPublishNow(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::EDIT, $user)) {
            return Response::json(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $type = (string)($data['content_type'] ?? '');
        $id = (int)($data['content_id'] ?? 0);

        $res = MultimediaReleaseService::publishNow($type, $id, $user);
        return Response::json($res, $res['success'] ? 200 : 400);
    }

    public function apiScheduleRelease(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::EDIT, $user)) {
            return Response::json(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $type = (string)($data['content_type'] ?? '');
        $id = (int)($data['content_id'] ?? 0);
        $publishAtLocal = trim((string)($data['publish_at'] ?? ''));
        $unpublishAtLocal = trim((string)($data['unpublish_at'] ?? ''));

        $publishAtUtc = MultimediaReleaseService::localToUtc($publishAtLocal) ?? $publishAtLocal;
        $unpublishAtUtc = $unpublishAtLocal !== '' ? (MultimediaReleaseService::localToUtc($unpublishAtLocal) ?? $unpublishAtLocal) : null;

        $res = MultimediaReleaseService::scheduleItem($type, $id, $publishAtUtc, $unpublishAtUtc, $user);
        return Response::json($res, $res['success'] ? 200 : 400);
    }

    public function apiCancelSchedule(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::EDIT, $user)) {
            return Response::json(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $type = (string)($data['content_type'] ?? '');
        $id = (int)($data['content_id'] ?? 0);

        $res = MultimediaReleaseService::cancelSchedule($type, $id, $user);
        return Response::json($res, $res['success'] ? 200 : 400);
    }

    public function apiRunDueReleases(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::EDIT, $user)) {
            return Response::json(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $limit = max(1, min(500, (int)($data['limit'] ?? $request->get('limit', 100))));
        $res = MultimediaReleaseService::processDueReleases($limit);
        return Response::json(['success' => true, 'summary' => $res]);
    }

    public function apiGetCalendar(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::VIEW, $user)) {
            return Response::json(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        $start = (string)$request->get('start', date('Y-m-01 00:00:00'));
        $end = (string)$request->get('end', date('Y-m-t 23:59:59'));
        $startUtc = MultimediaReleaseService::localToUtc($start) ?? $start;
        $endUtc = MultimediaReleaseService::localToUtc($end) ?? $end;

        $filters = [];
        if ($request->get('type')) {
            $filters['content_type'] = (string)$request->get('type');
        }
        if ($request->get('status')) {
            $filters['status'] = (string)$request->get('status');
        }

        $events = MultimediaReleaseService::getCalendarReleases($startUtc, $endUtc, $filters);
        return Response::json(['success' => true, 'events' => $events]);
    }

    public function apiGetQueue(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::VIEW, $user)) {
            return Response::json(['success' => false, 'error' => 'Permission denied.'], 403);
        }

        $filters = [];
        if ($request->get('type')) {
            $filters['content_type'] = (string)$request->get('type');
        }
        if ($request->get('status')) {
            $filters['status'] = (string)$request->get('status');
        }

        $queue = MultimediaReleaseService::getScheduledQueue($filters);
        return Response::json(['success' => true, 'queue' => $queue]);
    }

    private function resolvePublishingFields(Request $request, string $table, int $id): array
    {
        $user = $this->currentUser();
        $canPublish = MultimediaPermission::can(MultimediaPermission::PUBLISH, $user);

        $submitAction = trim((string)$request->post('submit_action', ''));
        if ($submitAction === 'publish') {
            $requestedStatus = 'published';
        } elseif ($submitAction === 'draft') {
            $requestedStatus = 'draft';
        } elseif ($submitAction === 'schedule') {
            $requestedStatus = 'scheduled';
        } elseif ($submitAction === 'pending') {
            $requestedStatus = 'pending';
        } else {
            $requestedStatus = (string)$request->post('status', 'published');
            if (!in_array($requestedStatus, ['draft', 'scheduled', 'published', 'unpublished', 'pending', 'rejected'], true)) {
                $requestedStatus = 'published';
            }
        }

        $publishAtRaw = trim((string)$request->post('publish_at', ''));
        $unpublishAtRaw = trim((string)$request->post('unpublish_at', ''));
        $publishAtUtc = $publishAtRaw !== '' ? MultimediaReleaseService::localToUtc($publishAtRaw) : null;
        $unpublishAtUtc = $unpublishAtRaw !== '' ? MultimediaReleaseService::localToUtc($unpublishAtRaw) : null;

        $hasPublishedAt = !in_array($table, ['multimedia_albums', 'multimedia_playlists'], true);
        $wasPublished = false;
        $existingStatus = null;
        if ($id > 0) {
            $selectCols = $hasPublishedAt ? "status, published_at" : "status";
            $existing = $this->db->selectOne("SELECT {$selectCols} FROM {$table} WHERE id = ?", [$id]);
            if ($existing) {
                $existingStatus = (string)($existing->status ?? '');
                if ($existing->status === 'published' || $existing->status === 'scheduled') {
                    $wasPublished = true;
                }
            }
        }

        // Enforce moderation workflow: non-publishers cannot publish directly
        if (!$canPublish) {
            if ($submitAction === 'draft' || $requestedStatus === 'draft') {
                $status = 'draft';
            } elseif ($existingStatus === 'rejected') {
                // Rejected item remains rejected on edit unless explicitly resubmitted
                $status = ($submitAction === 'resubmit' || $submitAction === 'pending') ? 'pending' : 'rejected';
            } elseif ($existingStatus === 'draft') {
                // Draft item remains draft on edit unless explicitly submitted for publish
                $status = ($submitAction === 'publish' || $submitAction === 'pending') ? 'pending' : 'draft';
            } else {
                // published or pending + edit => pending
                $status = 'pending';
            }
        } else {
            $status = $requestedStatus;
        }

        $publishedAt = null;
        if ($status === 'published' && $hasPublishedAt) {
            if ($id > 0) {
                $existing = $this->db->selectOne("SELECT published_at FROM {$table} WHERE id = ?", [$id]);
                $publishedAt = ($existing && !empty($existing->published_at)) ? $existing->published_at : gmdate('Y-m-d H:i:s');
            } else {
                $publishedAt = gmdate('Y-m-d H:i:s');
            }
        }

        return [
            'status'        => $status,
            'publish_at'    => $publishAtUtc,
            'published_at'  => $publishedAt,
            'unpublish_at'  => $unpublishAtUtc,
            'was_published' => $wasPublished,
        ];
    }

    /**
     * Process unified media source creation / update from content forms.
     */
    private function handleMediaSourceUploadOrUrl(Request $request, string $contentType, int $contentId): ?array
    {
        if ($contentType === 'song') {
            $rawSources = $request->post('media_sources', []);
            $firstSource = (is_array($rawSources) && isset($rawSources[0]) && is_array($rawSources[0])) ? $rawSources[0] : [];

            $audioFile = $request->file('audio_file') ?? ($_FILES['audio_file'] ?? null);
            $audioUrl = trim((string)$request->post('audio_url', ''));
            $audioUrl = MediaSourceResolver::extractIframeUrl($audioUrl);
            $audioLabel = trim((string)$request->post('audio_source_label', $request->post('source_label', '')));

            $videoFile = $request->file('video_file') ?? ($_FILES['video_file'] ?? null);
            $videoUrl = trim((string)($firstSource['url'] ?? $firstSource['url_or_path'] ?? $request->post('video_url', $request->post('url_or_path', $request->post('embed_url', $request->post('media_url', ''))))));
            $videoUrl = MediaSourceResolver::extractIframeUrl($videoUrl);
            $videoLabel = trim((string)($firstSource['label'] ?? $request->post('video_source_label', $request->post('source_label', ''))));
            $videoType = trim((string)($firstSource['source_type'] ?? $request->post('source_type', '')));

            $result = null;

            // 1. Process Song Audio Source
            if ($audioFile && is_array($audioFile) && !empty($audioFile['tmp_name']) && ($audioFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($audioFile['size'] ?? 0) > 0) {
                $secValidation = UploadSecurityService::validate($audioFile, 'audio');
                if (!$secValidation['valid']) {
                    $_SESSION['flash_error'] = $secValidation['error'];
                } else {
                    $origName = $secValidation['sanitized_name'];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $disk = MediaStorageManager::getDisk();
                    $key = MediaStorageManager::buildKey('song', $contentId, 'audio', $origName);
                    $stream = @fopen($audioFile['tmp_name'], 'rb');
                    if ($stream) {
                        $mime = $secValidation['mime'] ?: ($audioFile['type'] ?? 'audio/mpeg');
                        $disk->put($key, $stream, ['mime_type' => $mime]);
                        if (is_resource($stream)) { fclose($stream); }
                        $storedUrl = $disk->url($key);
                        $driver = (string)Setting::get('multimedia', 'storage_driver', 'local');
                        MediaStorageFile::recordFile($driver, $key, MediaStorageFile::TYPE_AUDIO, 'song', $contentId, null, null, (int)$audioFile['size'], $mime);

                        $saveResult = MediaSourceService::saveSource([
                            'content_type'    => 'song',
                            'content_id'      => $contentId,
                            'media_kind'      => 'audio',
                            'source_mode'     => 'local',
                            'source_type'     => 'audio',
                            'url_or_path'     => $storedUrl,
                            'label'           => $audioLabel ?: 'Main Audio',
                            'quality'         => 'original',
                            'mime_type'       => $mime,
                            'is_default'      => 1,
                            'replace_default' => true,
                            'status'          => 'active',
                        ]);
                        $sourceId = (int)$saveResult['source_id'];
                        $result = ['source_id' => $sourceId, 'url' => $storedUrl, 'type' => 'audio'];
                    }
                }
            } elseif ($audioUrl !== '') {
                $saveResult = MediaSourceService::saveSource([
                    'content_type'    => 'song',
                    'content_id'      => $contentId,
                    'media_kind'      => 'audio',
                    'source_type'     => 'audio',
                    'url_or_path'     => $audioUrl,
                    'label'           => $audioLabel ?: 'Main Audio',
                    'is_default'      => 1,
                    'replace_default' => true,
                    'status'          => 'active',
                ]);

                if (!$saveResult['success']) {
                    $_SESSION['flash_error'] = 'Could not save audio source: ' . ($saveResult['error'] ?? 'Validation error');
                } else {
                    $src = $saveResult['source'];
                    $result = ['source_id' => (int)$saveResult['source_id'], 'url' => $src ? $src->getUrlOrPath() : $audioUrl, 'type' => 'audio'];
                }
            }

            // 2. Process Song Video Source (Music Video)
            if ($videoFile && is_array($videoFile) && !empty($videoFile['tmp_name']) && ($videoFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($videoFile['size'] ?? 0) > 0) {
                $secValidation = UploadSecurityService::validate($videoFile, 'video');
                if (!$secValidation['valid']) {
                    $_SESSION['flash_error'] = $secValidation['error'];
                } else {
                    $origName = $secValidation['sanitized_name'];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    $sourceType = ($ext === 'webm') ? 'webm' : 'mp4';
                    $disk = MediaStorageManager::getDisk();
                    $key = MediaStorageManager::buildKey('song', $contentId, 'video', $origName);
                    $stream = @fopen($videoFile['tmp_name'], 'rb');
                    if ($stream) {
                        $mime = $secValidation['mime'] ?: ($videoFile['type'] ?? 'video/mp4');
                        $disk->put($key, $stream, ['mime_type' => $mime]);
                        if (is_resource($stream)) { fclose($stream); }
                        $storedUrl = $disk->url($key);
                        $driver = (string)Setting::get('multimedia', 'storage_driver', 'local');
                        MediaStorageFile::recordFile($driver, $key, MediaStorageFile::TYPE_ORIGINAL, 'song', $contentId, null, null, (int)$videoFile['size'], $mime);

                        $saveResult = MediaSourceService::saveSource([
                            'content_type'    => 'song',
                            'content_id'      => $contentId,
                            'media_kind'      => 'video',
                            'source_mode'     => 'local',
                            'source_type'     => $sourceType,
                            'url_or_path'     => $storedUrl,
                            'label'           => $videoLabel ?: 'Music Video',
                            'quality'         => 'original',
                            'mime_type'       => $mime,
                            'is_default'      => 1,
                            'replace_default' => true,
                            'status'          => 'active',
                        ]);
                        $sourceId = (int)$saveResult['source_id'];

                        // Automatic transcode if FFmpeg available
                        if (FFmpegService::isAvailable() && (bool)Setting::get('multimedia', 'auto_transcode_uploads', true)) {
                            $inputPath = ($disk instanceof \FavoriteCMS\Multimedia\Storage\LocalMultimediaStorage)
                                ? $disk->getRootDirectory() . '/' . $key
                                : $storedUrl;
                            try {
                                MediaProcessingService::dispatchJob('song', $contentId, $inputPath, 'hls_transcode', [
                                    'renditions' => ['1080p', '720p', '480p'],
                                ], $sourceId);
                            } catch (\Throwable) {}
                        }

                        $result = ['source_id' => $sourceId, 'url' => $storedUrl, 'type' => $sourceType];
                    }
                }
            } elseif ($videoUrl !== '') {
                $saveResult = MediaSourceService::saveSource([
                    'content_type'    => 'song',
                    'content_id'      => $contentId,
                    'media_kind'      => 'video',
                    'source_type'     => ($videoType !== '' && $videoType !== 'auto') ? $videoType : null,
                    'url_or_path'     => $videoUrl,
                    'label'           => $videoLabel ?: 'Music Video',
                    'is_default'      => 1,
                    'replace_default' => true,
                    'status'          => 'active',
                ]);

                if (!$saveResult['success']) {
                    $err = $saveResult['error'] ?? 'Unsupported video stream.';
                    if ($videoType === 'embed') {
                        $_SESSION['flash_error'] = 'External Embed validation failed: ' . $err;
                    } else {
                        $_SESSION['flash_error'] = 'Could not save video stream: ' . $err;
                    }
                } else {
                    $src = $saveResult['source'];
                    $result = ['source_id' => (int)$saveResult['source_id'], 'url' => $src ? $src->getUrlOrPath() : $videoUrl, 'type' => $src ? $src->getSourceType() : 'video'];
                }
            }

            return $result;
        }

        // Movie or Episode handling
        $fileKey = 'video_file';
        $file = $request->file($fileKey) ?? ($_FILES[$fileKey] ?? null);

        // Canonical source payload extraction (supporting media_sources[0], video_url, url_or_path, embed_url, media_url)
        $rawSources = $request->post('media_sources', []);
        $firstSource = (is_array($rawSources) && isset($rawSources[0]) && is_array($rawSources[0])) ? $rawSources[0] : [];

        $url = trim((string)($firstSource['url'] ?? $firstSource['url_or_path'] ?? $request->post('video_url', $request->post('url_or_path', $request->post('embed_url', $request->post('media_url', ''))))));
        $url = MediaSourceResolver::extractIframeUrl($url);

        $manualType = trim((string)($firstSource['source_type'] ?? $request->post('source_type', '')));
        $label = trim((string)($firstSource['label'] ?? $request->post('source_label', $request->post('label', ''))));

        // 1. Direct file upload
        if ($file && is_array($file) && !empty($file['tmp_name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($file['size'] ?? 0) > 0) {
            $secValidation = UploadSecurityService::validate($file, 'video');
            if (!$secValidation['valid']) {
                $_SESSION['flash_error'] = $secValidation['error'];
                return null;
            }
            $origName = $secValidation['sanitized_name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $sourceType = ($ext === 'webm') ? 'webm' : 'mp4';

            $disk = MediaStorageManager::getDisk();
            $key = MediaStorageManager::buildKey($contentType, $contentId, 'original', $origName);

            $stream = @fopen($file['tmp_name'], 'rb');
            if (!$stream) {
                $_SESSION['flash_error'] = "Could not open uploaded file for reading.";
                return null;
            }

            $mime = $secValidation['mime'] ?: ($file['type'] ?? 'video/mp4');
            $stored = $disk->put($key, $stream, ['mime_type' => $mime]);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (!$stored) {
                $_SESSION['flash_error'] = "Failed to store uploaded media file.";
                return null;
            }

            $storedUrl = $disk->url($key);
            $driver = (string)Setting::get('multimedia', 'storage_driver', 'local');
            MediaStorageFile::recordFile(
                $driver,
                $key,
                MediaStorageFile::TYPE_ORIGINAL,
                $contentType,
                $contentId,
                null,
                null,
                (int)$file['size'],
                $mime
            );

            // Upsert default MediaSource record (preventing duplicates upon resubmission)
            $existing = $this->db->selectOne(
                "SELECT id FROM multimedia_sources WHERE content_type = ? AND content_id = ? AND (media_kind = 'video' OR (media_kind IS NULL AND source_type != 'audio')) AND is_default = 1 LIMIT 1",
                [$contentType, $contentId]
            ) ?: $this->db->selectOne(
                "SELECT id FROM multimedia_sources WHERE content_type = ? AND content_id = ? AND (media_kind = 'video' OR (media_kind IS NULL AND source_type != 'audio')) ORDER BY id ASC LIMIT 1",
                [$contentType, $contentId]
            );

            $saveResult = MediaSourceService::saveSource([
                'content_type'    => $contentType,
                'content_id'      => $contentId,
                'media_kind'      => 'video',
                'source_mode'     => 'local',
                'source_type'     => $sourceType,
                'url_or_path'     => $storedUrl,
                'label'           => $label ?: 'Original Video',
                'quality'         => 'original',
                'mime_type'       => $mime,
                'is_default'      => 1,
                'replace_default' => true,
                'status'          => 'active',
            ]);

            $sourceId = (int)$saveResult['source_id'];

            // Automatic processing / transcode if FFmpeg is available and configured
            if (FFmpegService::isAvailable()) {
                $autoTranscode = (bool)Setting::get('multimedia', 'auto_transcode_uploads', true);
                if ($autoTranscode) {
                    $inputPath = ($disk instanceof \FavoriteCMS\Multimedia\Storage\LocalMultimediaStorage)
                        ? $disk->getRootDirectory() . '/' . $key
                        : $storedUrl;
                    try {
                        MediaProcessingService::dispatchJob($contentType, $contentId, $inputPath, 'hls_transcode', [
                            'renditions' => ['1080p', '720p', '480p'],
                        ], $sourceId);
                    } catch (\Throwable) {
                        // Keep original playable source intact
                    }
                }
            }

            return ['source_id' => $sourceId, 'url' => $storedUrl, 'type' => $sourceType];
        }

        // 2. Direct URL / External Embed Stream
        if ($url !== '') {
            $manualType = trim((string)($firstSource['source_type'] ?? $request->post('source_type', '')));
            $saveResult = MediaSourceService::saveSource([
                'content_type'    => $contentType,
                'content_id'      => $contentId,
                'media_kind'      => 'video',
                'source_type'     => ($manualType !== '' && $manualType !== 'auto') ? $manualType : null,
                'url_or_path'     => $url,
                'label'           => $label,
                'is_default'      => 1,
                'replace_default' => true,
                'status'          => 'active',
            ]);

            if (!$saveResult['success']) {
                $err = $saveResult['error'] ?? 'Unsupported media stream.';
                if ($manualType === 'embed') {
                    $_SESSION['flash_error'] = 'External Embed validation failed: ' . $err;
                } else {
                    $_SESSION['flash_error'] = 'Could not save media source: ' . $err;
                }
                return null;
            }

            $src = $saveResult['source'];
            return [
                'source_id' => (int)$saveResult['source_id'],
                'url'       => $src ? $src->getUrlOrPath() : $url,
                'type'      => $src ? $src->getSourceType() : 'video'
            ];
        }

        return null;
    }

    /**
     * Process artwork image upload (poster, backdrop, thumbnail, cover).
     */
    private function handleImageUpload(Request $request, string $inputName, string $contentType, int $contentId, string $subfolder): ?string
    {
        $file = $request->file($inputName) ?? ($_FILES[$inputName] ?? null);
        if (!$file || !is_array($file) || empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0) {
            return null;
        }

        $secValidation = UploadSecurityService::validate($file, 'image');
        if (!$secValidation['valid']) {
            $_SESSION['flash_error'] = $secValidation['error'];
            return null;
        }

        $origName = $secValidation['sanitized_name'];
        $disk = MediaStorageManager::getDisk();
        $key = MediaStorageManager::buildKey($contentType, $contentId, $subfolder, $origName);

        $stream = @fopen($file['tmp_name'], 'rb');
        if (!$stream) {
            return null;
        }

        $mime = $secValidation['mime'] ?: ($file['type'] ?? 'image/jpeg');
        $stored = $disk->put($key, $stream, ['mime_type' => $mime]);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$stored) {
            return null;
        }

        $url = $disk->url($key);
        $driver = (string)Setting::get('multimedia', 'storage_driver', 'local');
        MediaStorageFile::recordFile(
            $driver,
            $key,
            MediaStorageFile::TYPE_THUMBNAIL,
            $contentType,
            $contentId,
            null,
            null,
            (int)$file['size'],
            $mime
        );

        return $url;
    }

    /**
     * Process optional subtitle track upload.
     */
    private function handleSubtitleUpload(Request $request, string $contentType, int $contentId): void
    {
        $file = $request->file('subtitle_file') ?? ($_FILES['subtitle_file'] ?? null);
        if (!$file || !is_array($file) || empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0) {
            return;
        }

        $secValidation = UploadSecurityService::validate($file, 'subtitle');
        if (!$secValidation['valid']) {
            $_SESSION['flash_error'] = $secValidation['error'];
            return;
        }

        $origName = $secValidation['sanitized_name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $disk = MediaStorageManager::getDisk();
        $key = MediaStorageManager::buildKey($contentType, $contentId, 'subtitles', $origName);

        $stream = @fopen($file['tmp_name'], 'rb');
        if (!$stream) {
            return;
        }

        $mime = $secValidation['mime'] ?: 'text/vtt';
        $stored = $disk->put($key, $stream, ['mime_type' => $mime]);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$stored) {
            return;
        }

        $lang = trim((string)$request->post('subtitle_lang', 'en')) ?: 'en';
        $label = trim((string)$request->post('subtitle_label', 'English')) ?: 'English';
        $hasDefault = (bool)$this->db->selectOne(
            "SELECT id FROM multimedia_subtitles WHERE content_type = ? AND content_id = ? AND is_default = 1",
            [$contentType, $contentId]
        );

        $this->db->insert('multimedia_subtitles', [
            'content_type' => $contentType,
            'content_id'   => $contentId,
            'language'     => $lang,
            'label'        => $label,
            'file_or_url'  => $disk->url($key),
            'format'       => $ext,
            'is_default'   => $hasDefault ? 0 : 1,
            'sort_order'   => 0,
        ]);
    }

    /**
     * Compute media status badge metadata for content list tables.
     */
    public function getMediaStatusForContent(string $contentType, int $contentId): array
    {
        try {
            $sources = MediaSource::getForContent($contentType, $contentId, false);
            if (empty($sources)) {
                return [
                    'status'   => 'no_media',
                    'label'    => 'No Media',
                    'class'    => 'fav-badge-gray',
                    'playable' => false,
                ];
            }

            // Check if background processing is ongoing
            $jobs = MediaProcessingJob::getForContent($contentType, $contentId);
            $hasPendingJob = false;
            $hasFailedJob = false;
            foreach ($jobs as $job) {
                if (in_array($job->status, ['queued', 'processing'], true)) {
                    $hasPendingJob = true;
                    break;
                }
                if ($job->status === 'failed') {
                    $hasFailedJob = true;
                }
            }

            if ($hasPendingJob) {
                return [
                    'status'   => 'processing',
                    'label'    => 'Processing',
                    'class'    => 'fav-badge-warning',
                    'playable' => false,
                ];
            }

            $hasActive = false;
            foreach ($sources as $s) {
                if ($s->status === 'active') {
                    $hasActive = true;
                    break;
                }
            }

            if ($hasActive) {
                return [
                    'status'   => 'ready',
                    'label'    => 'Ready',
                    'class'    => 'fav-badge-success',
                    'playable' => true,
                ];
            }

            if ($hasFailedJob || !$hasActive) {
                return [
                    'status'   => 'failed',
                    'label'    => 'Failed',
                    'class'    => 'fav-badge-danger',
                    'playable' => false,
                ];
            }

            return [
                'status'   => 'ready',
                'label'    => 'Ready',
                'class'    => 'fav-badge-success',
                'playable' => true,
            ];
        } catch (\Throwable) {
            return [
                'status'   => 'error',
                'label'    => 'Check Failed',
                'class'    => 'fav-badge-warning',
                'playable' => false,
            ];
        }
    }

    public function processing(Request $request): string
    {
        if ($request->isPost()) {
            $this->validateCsrf($request);
            $action = (string)$request->post('action', '');

            if ($action === 'run_queue') {
                MediaProcessingService::processQueue(10);
                return (string)Response::redirect('/admin/page/multimedia-processing');
            } elseif ($action === 'retry_job') {
                $jobId = (int)$request->post('job_id', 0);
                MediaProcessingService::retryJob($jobId);
                return (string)Response::redirect('/admin/page/multimedia-processing');
            } elseif ($action === 'cancel_job') {
                $jobId = (int)$request->post('job_id', 0);
                MediaProcessingService::cancelJob($jobId);
                return (string)Response::redirect('/admin/page/multimedia-processing');
            }
        }

        $capabilities = FFmpegService::getCapabilities();
        $jobs = MediaProcessingJob::getRecent(50);
        $statusCounts = MediaProcessingJob::countByStatus();

        return $this->renderView('processing', [
            'ffmpegCapabilities' => $capabilities,
            'jobs'               => $jobs,
            'statusCounts'       => $statusCounts,
        ]);
    }

    public function apiDispatchProcessingJob(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SOURCES, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $contentType = (string)($data['content_type'] ?? '');
        $contentId = (int)($data['content_id'] ?? 0);
        $inputPath = (string)($data['input_path'] ?? '');
        $jobType = (string)($data['job_type'] ?? MediaProcessingJob::TYPE_FULL_PIPELINE);
        $settings = (array)($data['settings'] ?? []);
        $sourceId = isset($data['source_id']) ? (int)$data['source_id'] : null;

        $job = MediaProcessingService::dispatchJob($contentType, $contentId, $inputPath, $jobType, $settings, $sourceId);
        if (!$job) {
            return Response::json(['success' => false, 'error' => 'Failed to dispatch processing job.'], 400);
        }

        return Response::json(['success' => true, 'job' => $job]);
    }

    public function apiRetryProcessingJob(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SOURCES, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $jobId = (int)($data['job_id'] ?? 0);
        $res = MediaProcessingService::retryJob($jobId);
        $code = $res['success'] ? 200 : 400;
        return Response::json($res, $code);
    }

    public function apiCancelProcessingJob(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SOURCES, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $jobId = (int)($data['job_id'] ?? 0);
        $res = MediaProcessingService::cancelJob($jobId);
        $code = $res['success'] ? 200 : 400;
        return Response::json($res, $code);
    }

    public function apiRunProcessingQueue(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SOURCES, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $limit = (int)($data['limit'] ?? 5);
        $res = MediaProcessingService::processQueue($limit);
        return Response::json($res);
    }

    public function apiGetProcessingStatus(Request $request): Response
    {
        $capabilities = FFmpegService::getCapabilities();
        $statusCounts = MediaProcessingJob::countByStatus();
        return Response::json([
            'capabilities' => $capabilities,
            'counts'       => $statusCounts,
        ]);
    }

    // 17. Storage & CDN
    public function storage(Request $request): Response|string
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::make('<h1>403 Forbidden</h1><p>You do not have permission to manage storage settings.</p>', 403);
        }

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
            $driver    = (string)$request->post('multimedia_storage_driver', 'local');
            $endpoint  = trim((string)$request->post('multimedia_s3_endpoint', ''));
            $region    = trim((string)$request->post('multimedia_s3_region', 'us-east-1'));
            $bucket    = trim((string)$request->post('multimedia_s3_bucket', ''));
            $accessKey = trim((string)$request->post('multimedia_s3_access_key', ''));
            $secretKey = trim((string)$request->post('multimedia_s3_secret_key', ''));
            $prefix    = trim((string)$request->post('multimedia_s3_path_prefix', 'multimedia'));
            $cdnUrl    = trim((string)$request->post('multimedia_cdn_base_url', ''));
            $ttl       = (int)$request->post('multimedia_signed_url_ttl', 300);

            if ($driver === 's3' && !empty($endpoint)) {
                $check = MediaStorageManager::validateEndpointSecurity($endpoint);
                if (!$check['valid']) {
                    $_SESSION['flash_error'] = "Invalid S3 Endpoint: " . $check['reason'];
                    return Response::redirect('/admin/page/multimedia-storage');
                }
            }

            Setting::set('multimedia', 'storage_driver', $driver);
            Setting::set('multimedia', 's3_endpoint', $endpoint);
            Setting::set('multimedia', 's3_region', $region);
            Setting::set('multimedia', 's3_bucket', $bucket);
            Setting::set('multimedia', 's3_access_key', $accessKey);
            if ($secretKey !== '') {
                Setting::set('multimedia', 's3_secret_key', $secretKey);
            }
            Setting::set('multimedia', 's3_path_prefix', $prefix);
            Setting::set('multimedia', 'cdn_base_url', $cdnUrl);
            Setting::set('multimedia', 'signed_url_ttl', (string)$ttl);

            MediaStorageManager::resetInstances();
            $_SESSION['flash_success'] = 'Storage configuration updated successfully.';
            return Response::redirect('/admin/page/multimedia-storage');
        }

        $settings = [
            'multimedia_storage_driver' => Setting::get('multimedia', 'storage_driver', 'local'),
            'multimedia_s3_endpoint'   => Setting::get('multimedia', 's3_endpoint', 'https://s3.amazonaws.com'),
            'multimedia_s3_region'     => Setting::get('multimedia', 's3_region', 'us-east-1'),
            'multimedia_s3_bucket'     => Setting::get('multimedia', 's3_bucket', ''),
            'multimedia_s3_access_key' => Setting::get('multimedia', 's3_access_key', ''),
            'multimedia_s3_secret_key' => Setting::get('multimedia', 's3_secret_key', ''),
            'multimedia_s3_path_prefix'=> Setting::get('multimedia', 's3_path_prefix', 'multimedia'),
            'multimedia_cdn_base_url'  => Setting::get('multimedia', 'cdn_base_url', ''),
            'multimedia_signed_url_ttl'=> (int)Setting::get('multimedia', 'signed_url_ttl', 300),
        ];

        $stats = MediaStorageService::getStorageUsageSummary();

        return $this->renderView('storage', [
            'settings' => $settings,
            'stats'    => $stats,
        ]);
    }

    public function apiSaveStorageSettings(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $driver = (string)($data['multimedia_storage_driver'] ?? 'local');
        $endpoint = trim((string)($data['multimedia_s3_endpoint'] ?? ''));

        if ($driver === 's3' && !empty($endpoint)) {
            $check = MediaStorageManager::validateEndpointSecurity($endpoint);
            if (!$check['valid']) {
                return Response::json(['success' => false, 'error' => $check['reason']], 400);
            }
        }

        Setting::set('multimedia', 'storage_driver', $driver);
        Setting::set('multimedia', 's3_endpoint', $endpoint);
        Setting::set('multimedia', 's3_region', (string)($data['multimedia_s3_region'] ?? 'us-east-1'));
        Setting::set('multimedia', 's3_bucket', (string)($data['multimedia_s3_bucket'] ?? ''));
        Setting::set('multimedia', 's3_access_key', (string)($data['multimedia_s3_access_key'] ?? ''));

        $secret = trim((string)($data['multimedia_s3_secret_key'] ?? ''));
        if ($secret !== '') {
            Setting::set('multimedia', 's3_secret_key', $secret);
        }

        Setting::set('multimedia', 's3_path_prefix', (string)($data['multimedia_s3_path_prefix'] ?? 'multimedia'));
        Setting::set('multimedia', 'cdn_base_url', (string)($data['multimedia_cdn_base_url'] ?? ''));
        Setting::set('multimedia', 'signed_url_ttl', (string)($data['multimedia_signed_url_ttl'] ?? 300));

        MediaStorageManager::resetInstances();
        return Response::json(['success' => true, 'message' => 'Settings updated successfully.']);
    }

    public function apiTestStorageConnection(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $driver = (string)Setting::get('multimedia_storage_driver', 'local');
        $disk = MediaStorageManager::getDisk($driver);
        $res = $disk->testConnection();

        return Response::json($res);
    }

    public function apiMigrateStorage(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $sourceId = (int)($data['source_id'] ?? 0);
        $deleteLocal = (bool)($data['delete_local'] ?? false);

        if ($sourceId > 0) {
            $source = MediaSource::find($sourceId);
            if (!$source) {
                return Response::json(['success' => false, 'error' => 'Source not found.'], 404);
            }
            $res = MediaStorageService::migrateSourceToRemote($source, $deleteLocal);
            return Response::json($res);
        }

        // Batch migration of up to 10 un-migrated local sources
        $sources = $this->db->select("SELECT id FROM multimedia_sources WHERE (storage_driver IS NULL OR storage_driver = 'local') AND is_migrated = 0 LIMIT 10");
        $migrated = 0;
        foreach ($sources as $s) {
            $src = MediaSource::find((int)$s->id);
            if ($src) {
                $mRes = MediaStorageService::migrateSourceToRemote($src, $deleteLocal);
                if ($mRes['success'] ?? false) {
                    $migrated++;
                }
            }
        }

        return Response::json(['success' => true, 'migrated' => $migrated]);
    }

    public function apiCleanupOrphans(Request $request): Response
    {
        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $dryRun = (bool)($data['dry_run'] ?? true);
        $limit = (int)($data['limit'] ?? 100);

        $res = MediaStorageService::cleanupOrphans($dryRun, $limit);
        return Response::json($res);
    }

    public function apiGetStorageStats(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::MANAGE_SETTINGS, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $summary = MediaStorageService::getStorageUsageSummary();
        return Response::json($summary);
    }

    // 19. Localizations CRUD
    public function localizations(Request $request): Response|string
    {
        $action = (string)$request->post('action', $request->get('action', 'index'));

        if ($request->method() === 'POST') {
            $this->validateCsrf($request);

            if ($action === 'create' || $action === 'edit') {
                $id = (int)$request->post('id', 0);
                $contentType = (string)$request->post('content_type', 'movie');
                $contentId = (int)$request->post('content_id', 0);
                $rawLang = trim((string)$request->post('language_code', ''));
                $langCode = MediaLanguageService::normalizeLanguageCode($rawLang);
                $title = trim((string)$request->post('title', ''));
                $desc = trim((string)$request->post('description', ''));
                $tagline = trim((string)$request->post('tagline', ''));

                if (!MediaLanguageService::isValidCode($langCode)) {
                    $_SESSION['flash_error'] = 'Invalid BCP 47 language code: ' . htmlspecialchars($rawLang, ENT_QUOTES, 'UTF-8');
                } elseif (empty($title)) {
                    $_SESSION['flash_error'] = 'Title is required for localization.';
                } else {
                    MediaLocalization::saveLocalization($contentType, $contentId, $langCode, $title, $desc, $tagline);
                    $_SESSION['flash_success'] = "Localization saved for [{$langCode}].";
                }

                $retParams = "content_type={$contentType}&content_id={$contentId}";
                return Response::redirect('/admin/page/multimedia-localizations?' . $retParams);
            }

            if ($action === 'delete') {
                $id = (int)$request->post('id', 0);
                $ct = (string)$request->post('content_type', 'movie');
                $cid = (int)$request->post('content_id', 0);
                $lang = (string)$request->post('language_code', '');

                if ($id > 0) {
                    $this->db->delete('multimedia_localizations', ['id' => $id]);
                    $_SESSION['flash_success'] = 'Localization deleted.';
                } elseif ($cid > 0 && !empty($lang)) {
                    MediaLocalization::deleteLocalization($ct, $cid, $lang);
                    $_SESSION['flash_success'] = 'Localization deleted.';
                }

                return Response::redirect("/admin/page/multimedia-localizations?content_type={$ct}&content_id={$cid}");
            }
        }

        $contentType = (string)$request->get('content_type', '');
        $contentId = (int)$request->get('content_id', 0);
        $editId = (int)$request->get('edit', 0);
        $editLocalization = ($editId > 0) ? MediaLocalization::find($editId) : null;

        $items = (!empty($contentType) && $contentId > 0)
            ? MediaLocalization::getForContent($contentType, $contentId)
            : MediaLocalization::all();

        $movies = Movie::all();
        $series = Series::all();
        $episodes = Episode::all();
        $songs = Song::all();
        $languages = MediaLanguageService::getSupportedLanguages();

        return $this->renderView('localizations', [
            'items'            => $items,
            'contentType'      => $contentType,
            'contentId'        => $contentId,
            'editLocalization' => $editLocalization,
            'movies'           => $movies,
            'series'           => $series,
            'episodes'         => $episodes,
            'songs'            => $songs,
            'languages'        => $languages,
        ]);
    }

    public function apiSaveLocalization(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::EDIT, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $contentType = (string)($data['content_type'] ?? 'movie');
        $contentId = (int)($data['content_id'] ?? 0);
        $rawLang = trim((string)($data['language_code'] ?? ''));
        $langCode = MediaLanguageService::normalizeLanguageCode($rawLang);
        $title = trim((string)($data['title'] ?? ''));
        $desc = trim((string)($data['description'] ?? ''));
        $tagline = trim((string)($data['tagline'] ?? ''));

        if (!MediaLanguageService::isValidCode($langCode)) {
            return Response::json(['success' => false, 'error' => 'Invalid language code.'], 422);
        }
        if (empty($title)) {
            return Response::json(['success' => false, 'error' => 'Title is required.'], 422);
        }

        $saved = MediaLocalization::saveLocalization($contentType, $contentId, $langCode, $title, $desc, $tagline);

        return Response::json([
            'success'      => true,
            'localization' => $saved->toArray(),
        ]);
    }

    public function apiDeleteLocalization(Request $request): Response
    {
        $user = $this->currentUser();
        if (!$user || !MultimediaPermission::can(MultimediaPermission::DELETE, $user)) {
            return Response::json(['success' => false, 'error' => 'Unauthorized.'], 403);
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $contentType = (string)($data['content_type'] ?? '');
        $contentId = (int)($data['content_id'] ?? 0);
        $langCode = trim((string)($data['language_code'] ?? ''));

        if (!empty($contentType) && $contentId > 0 && !empty($langCode)) {
            MediaLocalization::deleteLocalization($contentType, $contentId, $langCode);
            return Response::json(['success' => true]);
        }

        return Response::json(['success' => false, 'error' => 'Missing parameters.'], 400);
    }

    public function apiGetLocalizations(Request $request): Response
    {
        $contentType = (string)$request->get('content_type', '');
        $contentId = (int)$request->get('content_id', 0);

        if (empty($contentType) || $contentId <= 0) {
            return Response::json(['success' => false, 'error' => 'content_type and content_id required.'], 400);
        }

        $locs = MediaLocalization::getForContent($contentType, $contentId);
        $data = array_map(fn($l) => $l->toArray(), $locs);

        return Response::json(['success' => true, 'localizations' => $data]);
    }

    /**
     * Process multiple download sources for a content item.
     * Handles updates to existing sources, insertions of new sources, explicit deletions,
     * and backward-compatible synchronization with single legacy download_url.
     */
    protected function handleDownloadSources(Request $request, string $contentType, int $contentId): void
    {
        if ($contentId <= 0) {
            return;
        }

        try {
            // 1. Process explicit deletions
            $deleteIds = (array)$request->post('delete_download_sources', []);
        foreach ($deleteIds as $delId) {
            $delId = (int)$delId;
            if ($delId > 0) {
                $this->db->execute(
                    "DELETE FROM `multimedia_download_sources` WHERE id = ? AND content_type = ? AND content_id = ?",
                    [$delId, $contentType, $contentId]
                );
            }
        }

        // 2. Process updates to existing download sources
        $newSources = (array)$request->post('new_download_sources', []);
        $existingSources = (array)$request->post('download_sources', []);
        foreach ($existingSources as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId <= 0) {
                $url = trim((string)($row['url'] ?? ''));
                if ($url !== '') {
                    $newSources[] = $row;
                }
                continue;
            }

            // Skip if marked for deletion
            if (in_array($rowId, array_map('intval', $deleteIds), true)) {
                continue;
            }

            $url = trim((string)($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $label = trim((string)($row['label'] ?? '')) ?: 'Download';
            $quality = trim((string)($row['quality'] ?? '')) ?: null;
            $format = trim((string)($row['format'] ?? '')) ?: DownloadSource::inferFormat($url);
            $provider = trim((string)($row['provider'] ?? '')) ?: DownloadSource::inferProvider($url);
            $isActive = !empty($row['is_active']) ? 1 : 0;
            $sortOrder = (int)($row['sort_order'] ?? 0);

            $this->db->execute(
                "UPDATE `multimedia_download_sources` SET 
                    label = ?, 
                    url = ?, 
                    quality = ?, 
                    format = ?, 
                    provider = ?, 
                    is_active = ?, 
                    sort_order = ?, 
                    updated_at = ? 
                 WHERE id = ? AND content_type = ? AND content_id = ?",
                [
                    $label,
                    $url,
                    $quality,
                    $format,
                    $provider,
                    $isActive,
                    $sortOrder,
                    gmdate('Y-m-d H:i:s'),
                    $rowId,
                    $contentType,
                    $contentId,
                ]
            );
        }

        // 3. Process newly added download sources
        foreach ($newSources as $newRow) {
            if (!is_array($newRow)) {
                continue;
            }
            $url = trim((string)($newRow['url'] ?? ''));
            if ($url === '') {
                continue; // Blank new download fields mean no change, not addition
            }

            $label = trim((string)($newRow['label'] ?? '')) ?: 'Download';
            $quality = trim((string)($newRow['quality'] ?? '')) ?: null;
            $format = trim((string)($newRow['format'] ?? '')) ?: DownloadSource::inferFormat($url);
            $provider = trim((string)($newRow['provider'] ?? '')) ?: DownloadSource::inferProvider($url);
            $isActive = !empty($newRow['is_active']) ? 1 : 0;
            $sortOrder = (int)($newRow['sort_order'] ?? 0);

            $this->db->insert('multimedia_download_sources', [
                'content_type' => $contentType,
                'content_id'   => $contentId,
                'label'        => $label,
                'url'          => $url,
                'quality'      => $quality,
                'format'       => $format,
                'provider'     => $provider,
                'is_active'    => $isActive,
                'sort_order'   => $sortOrder,
                'created_at'   => gmdate('Y-m-d H:i:s'),
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ]);
        }
        } catch (\Throwable) {
            // Gracefully ignore if multimedia_download_sources table does not exist
        }
    }

    private function renderView(string $viewName, array $data = []): string
    {
        $viewPath = __DIR__ . '/../../views/admin/' . $viewName . '.php';
        if (!file_exists($viewPath)) {
            return "<div class='notice notice-error'>View not found: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8') . "</div>";
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        return (string)ob_get_clean();
    }

    private function validateCsrf(Request $request): void
    {
        $token = (string)($request->post('_token') ?? $request->post('_csrf_token') ?? '');
        $sessionToken = (string)($_SESSION['_token'] ?? $_SESSION['csrf_token'] ?? '');
        if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            http_response_code(403);
            echo "Invalid CSRF Token.";
            exit;
        }
    }

    private function validateApiCsrf(Request $request, array $data): bool
    {
        $token = (string)($data['_token'] ?? $data['csrf_token'] ?? $request->header('X-CSRF-TOKEN') ?? $request->header('X-XSRF-TOKEN') ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_token'] ?? $_SESSION['_token'] ?? '');
        if ($sessionToken !== '') {
            return ($token !== '' && hash_equals($sessionToken, $token));
        }
        return true;
    }

    /**
     * Gracefully handle permission-denied delete actions.
     * Returns structured HTTP 403 JSON for AJAX/fetch requests,
     * or redirects back to the originating page with flash_error for standard POST.
     */
    private function deleteDeniedResponse(Request $request, string $message, string $fallbackUrl): Response
    {
        $isAjax = $request->isAjax()
            || str_contains(strtolower($request->header('Accept')), 'application/json')
            || strtolower($request->header('X_REQUESTED_WITH')) === 'xmlhttprequest'
            || strtolower($request->header('X-Requested-With')) === 'xmlhttprequest'
            || $request->get('ajax') === '1'
            || $request->post('ajax') === '1';

        if ($isAjax) {
            return Response::json([
                'success' => false,
                'error'   => $message,
            ], 403);
        }

        $_SESSION['flash_error'] = $message;

        $redirectTo = (string)$request->post('redirect_to', '');
        if (!empty($redirectTo) && str_starts_with($redirectTo, '/admin')) {
            return Response::redirect($redirectTo);
        }

        $referer = (string)$request->header('Referer', '');
        if (!empty($referer)) {
            $parsed = parse_url($referer);
            $path = $parsed['path'] ?? '';
            $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            if (str_starts_with($path, '/admin')) {
                return Response::redirect($path . $query);
            }
        }

        return Response::redirect($fallbackUrl);
    }

    /**
     * Gracefully handle successful delete actions.
     * Returns structured HTTP 200 JSON for AJAX/fetch requests,
     * or redirects to fallbackUrl with flash_success for standard POST.
     */
    private function deleteSuccessResponse(Request $request, string $message, string $fallbackUrl): Response
    {
        $isAjax = $request->isAjax()
            || str_contains(strtolower($request->header('Accept')), 'application/json')
            || strtolower($request->header('X_REQUESTED_WITH')) === 'xmlhttprequest'
            || strtolower($request->header('X-Requested-With')) === 'xmlhttprequest'
            || $request->get('ajax') === '1'
            || $request->post('ajax') === '1';

        if ($isAjax) {
            return Response::json([
                'success' => true,
                'message' => $message,
            ], 200);
        }

        $_SESSION['flash_success'] = $message;

        $redirectTo = (string)$request->post('redirect_to', '');
        if (!empty($redirectTo) && str_starts_with($redirectTo, '/admin')) {
            return Response::redirect($redirectTo);
        }

        return Response::redirect($fallbackUrl);
    }
}

