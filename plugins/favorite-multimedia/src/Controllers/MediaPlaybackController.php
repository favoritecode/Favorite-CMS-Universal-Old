<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Multimedia\Models\AnalyticsEvent;
use FavoriteCMS\Multimedia\Models\Favorite;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\PlaybackProgress;
use FavoriteCMS\Multimedia\Models\Subtitle;
use FavoriteCMS\Multimedia\Models\UserLanguagePreference;
use FavoriteCMS\Multimedia\Models\MultimediaComment;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;
use FavoriteCMS\Multimedia\Services\MediaDeliveryService;
use FavoriteCMS\Multimedia\Services\MediaDeliveryTokenService;
use FavoriteCMS\Multimedia\Services\MediaLanguageService;
use FavoriteCMS\Multimedia\Services\MediaSourceResolver;
use FavoriteCMS\Multimedia\Services\MultimediaAccessService;
use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;
use FavoriteCMS\Multimedia\Services\MultimediaNotificationService;
use FavoriteCMS\Multimedia\Services\MultimediaSubscriptionService;
use FavoriteCMS\Multimedia\Services\NextItemResolverService;
use FavoriteCMS\Multimedia\Services\PlaybackProgressService;
use FavoriteCMS\Multimedia\Storage\MediaStorageManager;

class MediaPlaybackController
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Reject request if current user is suspended.
     */
    private function rejectIfSuspended(?User $user): ?Response
    {
        if ($user && MultimediaPermission::isSuspendedUser($user)) {
            return Response::json([
                'success' => false,
                'status'  => 'forbidden',
                'error'   => 'Account suspended.',
            ], 403);
        }
        return null;
    }

    /**
     * Protected media stream delivery endpoint.
     */
    public function stream(Request $request, string $id): ?Response
    {
        $source = MediaSource::find((int)$id);
        if (!$source || $source->status !== 'active') {
            Logger::info('Favorite Multimedia: Stream requested for nonexistent or inactive source', ['source_id' => $id]);
            return Response::make('Media source not found or inactive.', 404);
        }

        $user = current_user();
        $accessState = MultimediaAccessService::checkAccess($user, (string)$source->content_type, (int)$source->content_id);

        if ($accessState !== MultimediaAccessService::ALLOW) {
            Logger::info('Favorite Multimedia: Stream access denied', [
                'source_id'    => $id,
                'access_state' => $accessState,
                'content_type' => $source->content_type,
                'content_id'   => $source->content_id,
            ]);
            if ($accessState === MultimediaAccessService::PREMIUM_REQUIRED) {
                AnalyticsEvent::logEvent((string)$source->content_type, (int)$source->content_id, 'premium_denied', $user ? (int)$user->id : null);
            }
            return match ($accessState) {
                MultimediaAccessService::LOGIN_REQUIRED   => Response::make('Authentication required to stream this content.', 401),
                MultimediaAccessService::PREMIUM_REQUIRED => Response::make('Premium membership required to stream this content.', 403),
                MultimediaAccessService::FORBIDDEN        => Response::make('Access forbidden.', 403),
                default                                   => Response::make('Not found.', 404),
            };
        }

        // Determine if protected
        $model = MultimediaAccessService::findContentModel((string)$source->content_type, (int)$source->content_id);
        $effectiveMode = $model ? MultimediaAccessService::resolveEffectiveAccessMode((string)$source->content_type, $model) : 'public';
        $isProtected = ($effectiveMode !== 'public') || ($user !== null);

        // Log analytics play event with discovery source
        $discoverySource = (string)$request->get('src', $request->get('discovery_source', ''));
        AnalyticsEvent::logEvent(
            (string)$source->content_type,
            (int)$source->content_id,
            'play',
            $user ? (int)$user->id : null,
            $discoverySource ?: null
        );

        MediaDeliveryService::streamSource($source, $isProtected);
        return null;
    }

    /**
     * Protected direct file download endpoint.
     */
    public function download(Request $request, string $id): ?Response
    {
        $source = MediaSource::find((int)$id);
        if (!$source || $source->status !== 'active') {
            Logger::info('Favorite Multimedia: Download requested for nonexistent or inactive source', ['source_id' => $id]);
            return Response::make('Media source not found or inactive.', 404);
        }

        $user = current_user();
        $downloadCheck = MultimediaAccessService::checkDownloadPermission(
            $user,
            (string)$source->content_type,
            (int)$source->content_id,
            $source
        );

        if (!$downloadCheck['allowed']) {
            // Check if parent content has an explicit manual download URL allowed
            $model = MultimediaAccessService::findContentModel((string)$source->content_type, (int)$source->content_id);
            if ($model && !empty($model->download_url)) {
                $contentCheck = MultimediaAccessService::checkDownloadPermission($user, (string)$source->content_type, $model);
                if ($contentCheck['allowed']) {
                    return $this->downloadContent($request, (string)$source->content_type, (string)$source->content_id);
                }
            }

            Logger::info('Favorite Multimedia: Download permission denied', [
                'source_id' => $id,
                'reason'    => $downloadCheck['reason'] ?? '',
            ]);
            if (($downloadCheck['access_state'] ?? '') === MultimediaAccessService::NOT_FOUND) {
                return Response::make('Media source not found or inactive.', 404);
            }
            $code = ($downloadCheck['access_state'] === MultimediaAccessService::LOGIN_REQUIRED) ? 401 : 403;
            return Response::make("Download denied: " . htmlspecialchars($downloadCheck['reason'], ENT_QUOTES, 'UTF-8'), $code);
        }

        // Log analytics download event
        AnalyticsEvent::logEvent((string)$source->content_type, (int)$source->content_id, 'download', $user ? (int)$user->id : null);

        MediaDeliveryService::downloadSource($source, null, true);
        return null;
    }

    /**
     * Protected content-level manual download endpoint.
     * Enforces fail-closed authorization (PUBLIC / LOGIN / PREMIUM) via MultimediaAccessService
     * before redirecting or streaming the explicit download URL.
     */
    public function downloadContent(Request $request, string $contentType, string $id): ?Response
    {
        $model = MultimediaAccessService::findContentModel($contentType, (int)$id);
        if (!$model) {
            return Response::make('Content not found.', 404);
        }

        $user = current_user();
        $downloadCheck = MultimediaAccessService::checkDownloadPermission($user, $contentType, $model);

        if (!$downloadCheck['allowed']) {
            Logger::info('Favorite Multimedia: Content download permission denied', [
                'content_type' => $contentType,
                'content_id'   => $id,
                'reason'       => $downloadCheck['reason'] ?? '',
            ]);
            if (($downloadCheck['access_state'] ?? '') === MultimediaAccessService::NOT_FOUND) {
                return Response::make('Content not found.', 404);
            }
            $code = ($downloadCheck['access_state'] === MultimediaAccessService::LOGIN_REQUIRED) ? 401 : 403;
            return Response::make("Download denied: " . htmlspecialchars($downloadCheck['reason'] ?? 'Unauthorized', ENT_QUOTES, 'UTF-8'), $code);
        }

        $rawUrl = trim((string)($model->download_url ?? ''));
        if ($rawUrl === '') {
            return Response::make('No downloadable file is configured for this item.', 404);
        }

        // Log analytics download event
        AnalyticsEvent::logEvent($contentType, (int)$id, 'download', $user ? (int)$user->id : null);

        // If local relative file path
        if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $rawUrl) && !str_starts_with($rawUrl, '//')) {
            $disk = MediaStorageManager::getDisk('local');
            if ($disk->has($rawUrl)) {
                $stream = $disk->readStream($rawUrl);
                if ($stream) {
                    $size = $disk->size($rawUrl);
                    $mime = $disk->mimeType($rawUrl) ?: 'application/octet-stream';
                    $filename = basename($rawUrl);
                    header('Content-Type: ' . $mime);
                    header('Content-Length: ' . $size);
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Cache-Control: private, no-transform, no-store, must-revalidate');
                    fpassthru($stream);
                    fclose($stream);
                    exit;
                }
            }
        }

        // Safe external / CDN redirect
        return Response::redirect($rawUrl);
    }

    /**
     * Protected download endpoint for multi-source download links (DownloadSource).
     * Enforces fail-closed authorization (PUBLIC / LOGIN / PREMIUM) via MultimediaAccessService,
     * validates URL security, logs analytics events, and streams local files or redirects externally.
     */
    public function downloadSource(Request $request, string $id): ?Response
    {
        $dlSource = \FavoriteCMS\Multimedia\Models\DownloadSource::find((int)$id);
        if (!$dlSource || empty($dlSource->is_active)) {
            Logger::info('Favorite Multimedia: Download source not found or inactive', ['download_source_id' => $id]);
            return Response::make('Download source not found or inactive.', 404);
        }

        $contentType = (string)$dlSource->content_type;
        $contentId = (int)$dlSource->content_id;
        $model = MultimediaAccessService::findContentModel($contentType, $contentId);
        if (!$model) {
            return Response::make('Content not found.', 404);
        }

        $user = current_user();
        $downloadCheck = MultimediaAccessService::checkDownloadPermission($user, $contentType, $model);

        if (!$downloadCheck['allowed']) {
            Logger::info('Favorite Multimedia: Download source permission denied', [
                'download_source_id' => $id,
                'content_type'       => $contentType,
                'content_id'         => $contentId,
                'reason'             => $downloadCheck['reason'] ?? '',
            ]);
            if (($downloadCheck['access_state'] ?? '') === MultimediaAccessService::NOT_FOUND) {
                return Response::make('Content not found.', 404);
            }
            $code = ($downloadCheck['access_state'] === MultimediaAccessService::LOGIN_REQUIRED) ? 401 : 403;
            return Response::make("Download denied: " . htmlspecialchars($downloadCheck['reason'] ?? 'Unauthorized', ENT_QUOTES, 'UTF-8'), $code);
        }

        $rawUrl = trim((string)($dlSource->url ?? ''));
        if ($rawUrl === '') {
            return Response::make('No downloadable URL configured for this source.', 404);
        }

        // Validate URL security (reject dangerous schemes and internal SSRF targets)
        $isLocal = (!preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $rawUrl) && !str_starts_with($rawUrl, '//') && !str_contains($rawUrl, '..'));
        if (!$isLocal) {
            $check = MediaSourceResolver::validateUrlSecurity($rawUrl, false);
            if (empty($check['safe'])) {
                Logger::warning('Favorite Multimedia: Insecure download URL rejected', ['url' => $rawUrl, 'reason' => $check['reason'] ?? '']);
                return Response::make('Invalid or unsafe download URL.', 400);
            }
        }

        // Log analytics download event
        AnalyticsEvent::logEvent($contentType, $contentId, 'download', $user ? (int)$user->id : null);

        // If local relative file path
        if ($isLocal) {
            $disk = MediaStorageManager::getDisk('local');
            if ($disk->has($rawUrl)) {
                $stream = $disk->readStream($rawUrl);
                if ($stream) {
                    $size = $disk->size($rawUrl);
                    $mime = $disk->mimeType($rawUrl) ?: 'application/octet-stream';
                    $filename = basename($rawUrl);
                    header('Content-Type: ' . $mime);
                    header('Content-Length: ' . $size);
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Cache-Control: private, no-transform, no-store, must-revalidate');
                    fpassthru($stream);
                    fclose($stream);
                    exit;
                }
            }
        }

        // Safe external / CDN redirect
        return Response::redirect($rawUrl);
    }

    /**
     * Authorize access for HLS streams through central access service or scoped token.
     */
    private function authorizeHlsAccess(MediaSource $source, ?Request $request = null): ?Response
    {
        $user = current_user();

        // 1. Scoped HLS stream token authorization (for variant playlists & segments)
        if ($request !== null) {
            $token = (string)$request->get('token', '');
            $exp = (int)$request->get('exp', 0);
            if ($token !== '' && $exp > 0) {
                if (MediaDeliveryTokenService::validateHlsStreamToken($token, (int)$source->id, $exp)) {
                    return null; // Valid scoped stream token allows delivery
                }
            }
        }

        // 2. Centralized Favorite Digital / Access Service gatekeeper
        $accessState = MultimediaAccessService::checkAccess($user, (string)$source->content_type, (int)$source->content_id);

        if ($accessState !== MultimediaAccessService::ALLOW) {
            if ($accessState === MultimediaAccessService::PREMIUM_REQUIRED) {
                AnalyticsEvent::logEvent((string)$source->content_type, (int)$source->content_id, 'premium_denied', $user ? (int)$user->id : null);
            }
            return match ($accessState) {
                MultimediaAccessService::LOGIN_REQUIRED   => Response::make('Authentication required to stream HLS.', 401),
                MultimediaAccessService::PREMIUM_REQUIRED => Response::make('Premium membership required to stream HLS.', 403),
                MultimediaAccessService::FORBIDDEN        => Response::make('Access forbidden.', 403),
                default                                   => Response::make('Not found.', 404),
            };
        }

        return null;
    }

    /**
     * Protected adaptive HLS master playlist delivery.
     */
    public function hlsMaster(Request $request, string $id): Response
    {
        $source = MediaSource::find((int)$id);
        if (!$source || $source->status !== 'active') {
            return Response::make('Media source not found or inactive.', 404);
        }

        $denied = $this->authorizeHlsAccess($source, $request);
        if ($denied !== null) {
            return $denied;
        }

        $model = MultimediaAccessService::findContentModel((string)$source->content_type, (int)$source->content_id);
        $effectiveMode = $model ? MultimediaAccessService::resolveEffectiveAccessMode((string)$source->content_type, $model) : 'public';
        $isProtected = ($effectiveMode !== 'public') || (current_user() !== null);

        return MediaDeliveryService::deliverHlsMaster($source, $isProtected);
    }

    /**
     * Protected adaptive HLS variant playlist delivery.
     */
    public function hlsVariant(Request $request, string $id, string $quality): Response
    {
        $source = MediaSource::find((int)$id);
        if (!$source || $source->status !== 'active') {
            return Response::make('Media source not found or inactive.', 404);
        }

        $denied = $this->authorizeHlsAccess($source, $request);
        if ($denied !== null) {
            return $denied;
        }

        $model = MultimediaAccessService::findContentModel((string)$source->content_type, (int)$source->content_id);
        $effectiveMode = $model ? MultimediaAccessService::resolveEffectiveAccessMode((string)$source->content_type, $model) : 'public';
        $isProtected = ($effectiveMode !== 'public') || (current_user() !== null);

        return MediaDeliveryService::deliverHlsVariant($source, $quality, $isProtected);
    }

    /**
     * Protected adaptive HLS media segment delivery.
     */
    public function hlsSegment(Request $request, string $id, string $quality, string $segment): Response
    {
        $source = MediaSource::find((int)$id);
        if (!$source || $source->status !== 'active') {
            return Response::make('Media source not found or inactive.', 404);
        }

        $denied = $this->authorizeHlsAccess($source, $request);
        if ($denied !== null) {
            return $denied;
        }

        $model = MultimediaAccessService::findContentModel((string)$source->content_type, (int)$source->content_id);
        $effectiveMode = $model ? MultimediaAccessService::resolveEffectiveAccessMode((string)$source->content_type, $model) : 'public';
        $isProtected = ($effectiveMode !== 'public') || (current_user() !== null);

        return MediaDeliveryService::deliverHlsSegment($source, $quality, $segment, $isProtected);
    }

    /**
     * Protected token-stream endpoint for secure storage objects.
     */
    public function tokenStream(Request $request): Response
    {
        $key = (string)$request->get('key', '');
        $exp = (int)$request->get('exp', 0);
        $sig = (string)$request->get('sig', '');

        if ($key === '' || $exp <= 0 || $sig === '') {
            return Response::make('Missing token parameters.', 400);
        }

        if (!MediaDeliveryTokenService::validateToken($sig, $key, $exp)) {
            return Response::make('Invalid or expired delivery token.', 403);
        }

        $disk = MediaStorageManager::getDisk('local');
        $stream = $disk->readStream($key);
        if (!$stream) {
            return Response::make('Object not found.', 404);
        }

        $size = $disk->size($key);
        $content = stream_get_contents($stream);
        fclose($stream);

        return Response::make((string)$content, 200)
            ->header('Content-Length', (string)$size)
            ->header('Cache-Control', 'private, no-cache, no-store, must-revalidate');
    }

    /**
     * Protected subtitle track endpoint.
     */
    public function subtitle(Request $request, string $id): Response
    {
        $subtitle = Subtitle::find((int)$id);
        if (!$subtitle) {
            Logger::info('Favorite Multimedia: Subtitle track requested for nonexistent subtitle', ['subtitle_id' => $id]);
            return Response::make('Subtitle not found.', 404);
        }

        $user = current_user();
        $accessState = MultimediaAccessService::checkAccess($user, (string)$subtitle->content_type, (int)$subtitle->content_id);

        if ($accessState !== MultimediaAccessService::ALLOW) {
            Logger::info('Favorite Multimedia: Subtitle access denied', [
                'subtitle_id'  => $id,
                'access_state' => $accessState,
            ]);
            return Response::make("WEBVTT\n\n1\n00:00:00.000 --> 00:00:05.000\nSubtitles restricted.", 403)
                ->header('Content-Type', 'text/vtt; charset=utf-8');
        }

        $model = MultimediaAccessService::findContentModel((string)$subtitle->content_type, (int)$subtitle->content_id);
        $effectiveMode = $model ? MultimediaAccessService::resolveEffectiveAccessMode((string)$subtitle->content_type, $model) : 'public';
        $isProtected = ($effectiveMode !== 'public') || ($user !== null);

        return MediaDeliveryService::deliverSubtitle($subtitle, $isProtected);
    }

    /**
     * Standalone player embed view.
     */
    public function player(Request $request, string $id): Response
    {
        $source = MediaSource::find((int)$id);
        if (!$source) {
            return Response::make('Media source not found.', 404);
        }

        $user = current_user();
        $accessState = MultimediaAccessService::checkAccess($user, (string)$source->content_type, (int)$source->content_id);
        if ($accessState === MultimediaAccessService::NOT_FOUND) {
            return Response::make('Media source not found.', 404);
        }
        $downloadInfo = MultimediaAccessService::checkDownloadPermission($user, (string)$source->content_type, (int)$source->content_id, $source);

        $viewPath = __DIR__ . '/../../views/frontend/player-embed.php';
        extract([
            'source'       => $source,
            'accessState'  => $accessState,
            'downloadInfo' => $downloadInfo,
        ], EXTR_SKIP);

        ob_start();
        include $viewPath;
        $html = (string)ob_get_clean();

        return Response::make($html, 200);
    }

    /**
     * API for automatic URL detection in admin panel.
     */
    public function apiDetect(Request $request): Response
    {
        $url = trim((string)$request->get('url', ''));
        $manual = $request->get('type');

        $result = MediaSourceResolver::resolve($url, 'auto', $manual ?: null);
        return Response::json($result);
    }

    /**
     * API to retrieve saved playback progress for content item.
     */
    public function apiGetProgress(Request $request): Response
    {
        $contentType = trim((string)$request->get('content_type', ''));
        $contentId = (int)$request->get('content_id', 0);
        $user = current_user();

        $result = PlaybackProgressService::getProgress($user, $contentType, $contentId);
        return Response::json($result);
    }

    /**
     * API to save throttled playback progress for authenticated user.
     */
    public function apiSaveProgress(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        $contentType = trim((string)($data['content_type'] ?? ''));
        $contentId = (int)($data['content_id'] ?? 0);
        $position = (float)($data['position'] ?? 0.0);
        $duration = (float)($data['duration'] ?? 0.0);
        $forceCompleted = !empty($data['is_completed']);

        $result = PlaybackProgressService::saveProgress(
            $user,
            $contentType,
            $contentId,
            $position,
            $duration,
            $forceCompleted
        );

        $code = $result['code'] ?? ($result['success'] ? 200 : 400);
        return Response::json($result, $code);
    }

    /**
     * API to toggle favorite status ("My List").
     */
    public function apiToggleFavorite(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        $contentType = trim((string)($data['content_type'] ?? ''));
        $contentId = (int)($data['content_id'] ?? 0);

        $result = Favorite::toggleFavorite((int)$user->id, $contentType, $contentId);
        return Response::json($result);
    }

    /**
     * API to remove an individual history entry.
     */
    public function apiRemoveHistory(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        $contentType = trim((string)($data['content_type'] ?? ''));
        $contentId = (int)($data['content_id'] ?? 0);

        $ok = PlaybackProgress::deleteEntry((int)$user->id, $contentType, $contentId);
        return Response::json(['success' => $ok]);
    }

    /**
     * API to clear all watch/listening history for current user.
     */
    public function apiClearHistory(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();
        $contentType = !empty($data['content_type']) ? trim((string)$data['content_type']) : null;

        $ok = PlaybackProgress::clearForUser((int)$user->id, $contentType);
        return Response::json(['success' => $ok]);
    }

    /**
     * API to fetch next episode in sequence.
     */
    public function apiNextEpisode(Request $request, string $id): Response
    {
        $user = current_user();
        $requireSources = ($request->get('require_sources') !== '0');
        $skipUnauthorized = (bool)($request->get('skip_unauthorized', false));

        $result = NextItemResolverService::resolveNextEpisode($user, (int)$id, [
            'require_sources'   => $requireSources,
            'skip_unauthorized' => $skipUnauthorized,
        ]);

        return Response::json($result);
    }

    /**
     * API to fetch next item in playlist.
     */
    public function apiNextPlaylistItem(Request $request, string $playlistId, string $currentItemId): Response
    {
        $user = current_user();
        $repeat = ($request->get('repeat') === '1' || $request->get('loop') === '1');
        $shuffle = ($request->get('shuffle') === '1');
        $skipUnauthorized = ($request->get('skip_unauthorized') !== '0');
        $requireSources = ($request->get('require_sources') !== '0');

        $result = NextItemResolverService::resolveNextPlaylistItem($user, (int)$playlistId, (int)$currentItemId, [
            'repeat'            => $repeat,
            'is_shuffle'        => $shuffle,
            'skip_unauthorized' => $skipUnauthorized,
            'require_sources'   => $requireSources,
        ]);

        return Response::json($result);
    }

    /**
     * API to rate a content item (1-5 stars).
     */
    public function apiRate(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Please sign in to rate.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $contentType = trim((string)($data['content_type'] ?? ''));
        $contentId = (int)($data['content_id'] ?? 0);
        $rating = (int)($data['rating'] ?? 0);

        if ($rating < 1 || $rating > 5) {
            return Response::json(['success' => false, 'error' => 'Rating must be an integer between 1 and 5 stars.'], 400);
        }

        $result = MultimediaEngagementService::rateContent($user, $contentType, $contentId, $rating);
        $code = $result['success'] ? 200 : 400;
        return Response::json($result, $code);
    }

    /**
     * API to remove rating.
     */
    public function apiRemoveRating(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $contentType = trim((string)($data['content_type'] ?? ''));
        $contentId = (int)($data['content_id'] ?? 0);

        $result = MultimediaEngagementService::removeRating($user, $contentType, $contentId);
        return Response::json($result);
    }

    /**
     * API to submit or update a review.
     */
    public function apiSaveReview(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $reviewId = isset($data['review_id']) ? (int)$data['review_id'] : 0;
        $body = (string)($data['body'] ?? '');
        $title = isset($data['title']) ? (string)$data['title'] : null;
        $rating = isset($data['rating']) ? (int)$data['rating'] : null;
        $isSpoiler = !empty($data['contains_spoiler']);

        if ($reviewId > 0) {
            $result = MultimediaEngagementService::updateReview($user, $reviewId, $body, $title, $rating, $isSpoiler);
        } else {
            $contentType = trim((string)($data['content_type'] ?? ''));
            $contentId = (int)($data['content_id'] ?? 0);
            $result = MultimediaEngagementService::createReview($user, $contentType, $contentId, $body, $title, $rating, $isSpoiler);
        }

        $code = $result['success'] ? 200 : (isset($result['status']) && $result['status'] === 'forbidden' ? 403 : 400);
        return Response::json($result, $code);
    }

    /**
     * API to delete review.
     */
    public function apiDeleteReview(Request $request, string $id): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $result = MultimediaEngagementService::deleteReview($user, (int)$id);
        $code = $result['success'] ? 200 : (isset($result['status']) && $result['status'] === 'forbidden' ? 403 : 400);
        return Response::json($result, $code);
    }

    /**
     * API to vote helpful for review.
     */
    public function apiHelpfulReview(Request $request, string $id): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $result = MultimediaEngagementService::voteHelpfulReview($user, (int)$id);
        return Response::json($result);
    }

    /**
     * API to submit comment or reply.
     */
    public function apiSaveComment(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Please sign in to join the discussion.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $commentId = isset($data['comment_id']) ? (int)$data['comment_id'] : 0;
        $body = (string)($data['body'] ?? '');
        $trimmed = trim($body);

        if ($trimmed === '') {
            return Response::json(['success' => false, 'error' => 'Comment cannot be empty.'], 400);
        }
        if (mb_strlen($trimmed, 'UTF-8') > 1000) {
            return Response::json(['success' => false, 'error' => 'Comment cannot exceed 1000 characters.'], 400);
        }

        if ($commentId > 0) {
            $result = MultimediaEngagementService::updateComment($user, $commentId, $trimmed);
        } else {
            $contentType = trim((string)($data['content_type'] ?? ''));
            $contentId = (int)($data['content_id'] ?? 0);
            $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
            $result = MultimediaEngagementService::createComment($user, $contentType, $contentId, $trimmed, $parentId);

            if ($result['success'] && !empty($result['comment']['parent_id'])) {
                $parent = MultimediaComment::find((int)$result['comment']['parent_id']);
                $reply = MultimediaComment::find((int)$result['comment']['id']);
                if ($parent && $reply) {
                    MultimediaNotificationService::onCommentReplied($parent, $reply);
                }
            }
        }

        $code = $result['success'] ? 200 : (isset($result['status']) && $result['status'] === 'forbidden' ? 403 : 400);
        return Response::json($result, $code);
    }

    /**
     * API to delete comment.
     */
    public function apiDeleteComment(Request $request, string $id): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $result = MultimediaEngagementService::deleteComment($user, (int)$id);
        $code = $result['success'] ? 200 : (isset($result['status']) && $result['status'] === 'forbidden' ? 403 : 400);
        return Response::json($result, $code);
    }

    /**
     * API to report review or comment.
     */
    public function apiReport(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $targetType = trim((string)($data['target_type'] ?? ''));
        $targetId = (int)($data['target_id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        $notes = isset($data['notes']) ? trim((string)$data['notes']) : null;

        $result = MultimediaEngagementService::createReport($user, $targetType, $targetId, $reason, $notes);
        $code = $result['success'] ? 200 : 400;
        return Response::json($result, $code);
    }

    /**
     * API to fetch reviews.
     */
    public function apiGetReviews(Request $request): Response
    {
        $contentType = trim((string)$request->get('content_type', ''));
        $contentId = (int)$request->get('content_id', 0);
        $limit = max(1, min(50, (int)$request->get('limit', 10)));
        $offset = max(0, (int)$request->get('offset', 0));
        $sort = (string)$request->get('sort', 'newest');

        $result = MultimediaEngagementService::getReviewsForContent($contentType, $contentId, $limit, $offset, $sort, current_user());
        return Response::json($result);
    }

    /**
     * API to fetch comments.
     */
    public function apiGetComments(Request $request): Response
    {
        $contentType = trim((string)$request->get('content_type', ''));
        $contentId = (int)$request->get('content_id', 0);
        $limit = max(1, min(50, (int)$request->get('limit', 20)));
        $offset = max(0, (int)$request->get('offset', 0));

        $result = MultimediaEngagementService::getCommentsForContent($contentType, $contentId, $limit, $offset, current_user());
        return Response::json($result);
    }

    /**
     * API to toggle follow/subscription.
     */
    public function apiToggleSubscribe(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $targetType = trim((string)($data['target_type'] ?? ''));
        $targetId = (int)($data['target_id'] ?? 0);

        $result = MultimediaSubscriptionService::toggle($user, $targetType, $targetId);
        $code = $result['success'] ? 200 : (isset($result['status']) && $result['status'] === 'unauthenticated' ? 401 : 400);
        return Response::json($result, $code);
    }

    /**
     * API to get user notifications.
     */
    public function apiGetNotifications(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $limit = max(1, min(50, (int)$request->get('limit', 20)));
        $offset = max(0, (int)$request->get('offset', 0));
        $unreadOnly = $request->get('unread_only') !== null ? (bool)$request->get('unread_only') : null;

        $result = MultimediaNotificationService::getNotifications($user, $limit, $offset, $unreadOnly);
        return Response::json($result, 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * API to get user unread notification count.
     */
    public function apiGetUnreadCount(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['unread_count' => 0, 'unread_badge' => '0'], 200, ['Cache-Control' => 'no-store, private']);
        }
        if ($this->rejectIfSuspended($user)) {
            return Response::json(['unread_count' => 0, 'unread_badge' => '0'], 200, ['Cache-Control' => 'no-store, private']);
        }

        $count = MultimediaNotificationService::getUnreadCount($user);
        return Response::json([
            'unread_count' => $count,
            'unread_badge' => $count > 99 ? '99+' : (string)$count,
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * API to mark single notification as read.
     */
    public function apiMarkNotificationRead(Request $request, string $id): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $ok = MultimediaNotificationService::markAsRead($user, (int)$id);
        $unreadCount = MultimediaNotificationService::getUnreadCount($user);

        return Response::json([
            'success'      => $ok,
            'unread_count' => $unreadCount,
            'unread_badge' => $unreadCount > 99 ? '99+' : (string)$unreadCount,
        ]);
    }

    /**
     * API to mark all notifications as read.
     */
    public function apiMarkAllNotificationsRead(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $ok = MultimediaNotificationService::markAllAsRead($user);

        return Response::json([
            'success'      => $ok,
            'unread_count' => 0,
            'unread_badge' => '0',
        ]);
    }

    /**
     * API to get notification preferences.
     */
    public function apiGetNotificationPreferences(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $prefs = MultimediaNotificationService::getPreferences($user);
        return Response::json(['preferences' => $prefs], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * API to save notification preferences.
     */
    public function apiSaveNotificationPreferences(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $prefs = (array)($data['preferences'] ?? $data);
        $ok = MultimediaNotificationService::savePreferences($user, $prefs);
        $saved = MultimediaNotificationService::getPreferences($user);

        return Response::json([
            'success'     => $ok,
            'preferences' => $saved,
        ]);
    }

    /**
     * Protected adaptive HLS audio variant playlist delivery.
     */
    public function hlsAudioVariant(Request $request, string $id, string $audioId): Response
    {
        $source = MediaSource::find((int)$id);
        if (!$source || $source->status !== 'active') {
            return Response::make('Media source not found or inactive.', 404);
        }

        $denied = $this->authorizeHlsAccess($source, $request);
        if ($denied !== null) {
            return $denied;
        }

        $audioSource = MediaSource::find((int)$audioId);
        if (!$audioSource || $audioSource->status !== 'active' || (int)$audioSource->content_id !== (int)$source->content_id || (string)$audioSource->content_type !== (string)$source->content_type) {
            return Response::make('Audio track not found or mismatch.', 404);
        }

        $model = MultimediaAccessService::findContentModel((string)$source->content_type, (int)$source->content_id);
        $effectiveMode = $model ? MultimediaAccessService::resolveEffectiveAccessMode((string)$source->content_type, $model) : 'public';
        $isProtected = ($effectiveMode !== 'public') || (current_user() !== null);

        return MediaDeliveryService::deliverAudioVariant($source, $audioSource, $isProtected);
    }

    /**
     * Protected adaptive HLS audio stream delivery.
     */
    public function hlsAudioStream(Request $request, string $id, string $audioId): ?Response
    {
        $source = MediaSource::find((int)$id);
        if (!$source || $source->status !== 'active') {
            return Response::make('Media source not found or inactive.', 404);
        }

        $denied = $this->authorizeHlsAccess($source, $request);
        if ($denied !== null) {
            return $denied;
        }

        $audioSource = MediaSource::find((int)$audioId);
        if (!$audioSource || $audioSource->status !== 'active' || (int)$audioSource->content_id !== (int)$source->content_id || (string)$audioSource->content_type !== (string)$source->content_type) {
            return Response::make('Audio track not found or mismatch.', 404);
        }

        $model = MultimediaAccessService::findContentModel((string)$source->content_type, (int)$source->content_id);
        $effectiveMode = $model ? MultimediaAccessService::resolveEffectiveAccessMode((string)$source->content_type, $model) : 'public';
        $isProtected = ($effectiveMode !== 'public') || (current_user() !== null);

        MediaDeliveryService::streamSource($audioSource, null, $isProtected);
        return null;
    }

    /**
     * API to get user language preferences.
     */
    public function apiGetUserLanguagePreferences(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $pref = UserLanguagePreference::getForUser((int)$user->id);
        return Response::json([
            'preferences' => $pref ? $pref->toArray() : [
                'preferred_audio_language'    => null,
                'preferred_subtitle_language' => null,
                'subtitle_enabled'            => 1,
            ],
            'default_language' => MediaLanguageService::getDefaultLanguage(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * API to save user language preferences.
     */
    public function apiSaveUserLanguagePreferences(Request $request): Response
    {
        $user = current_user();
        if (!$user) {
            return Response::json(['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'], 401);
        }
        if ($denied = $this->rejectIfSuspended($user)) {
            return $denied;
        }

        $raw = @file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        $data = is_array($json) ? array_merge($request->all(), $json) : $request->all();

        if (!$this->validateApiCsrf($request, $data)) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $audioLang = isset($data['preferred_audio_language']) && $data['preferred_audio_language'] !== '' ? (string)$data['preferred_audio_language'] : null;
        $subLang = isset($data['preferred_subtitle_language']) && $data['preferred_subtitle_language'] !== '' ? (string)$data['preferred_subtitle_language'] : null;
        $subEnabled = isset($data['subtitle_enabled']) ? (bool)$data['subtitle_enabled'] : true;

        if ($audioLang !== null && !MediaLanguageService::isValidCode($audioLang)) {
            return Response::json(['success' => false, 'error' => 'Invalid audio language code.'], 422);
        }
        if ($subLang !== null && !MediaLanguageService::isValidCode($subLang)) {
            return Response::json(['success' => false, 'error' => 'Invalid subtitle language code.'], 422);
        }

        $saved = UserLanguagePreference::saveForUser((int)$user->id, $audioLang, $subLang, $subEnabled);

        return Response::json([
            'success'     => true,
            'preferences' => $saved->toArray(),
        ]);
    }

    private function validateApiCsrf(Request $request, array $data): bool
    {
        $token = (string)($data['_token'] ?? $data['csrf_token'] ?? $request->header('X-CSRF-TOKEN') ?? $request->header('X-XSRF-TOKEN') ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $tokens = array_filter([
            (string)($_SESSION['_token'] ?? ''),
            (string)($_SESSION['csrf_token'] ?? ''),
        ], fn($t) => $t !== '');

        if (!empty($tokens)) {
            if ($token === '') {
                return false;
            }
            foreach ($tokens as $sessionToken) {
                if (hash_equals($sessionToken, $token)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }
}

