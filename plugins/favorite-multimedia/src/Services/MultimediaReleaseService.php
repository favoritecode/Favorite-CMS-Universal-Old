<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use DateTime;
use DateTimeZone;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\MediaSource;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;

class MultimediaReleaseService
{
    // Canonical content statuses
    public const STATUS_DRAFT       = 'draft';
    public const STATUS_SCHEDULED   = 'scheduled';
    public const STATUS_PUBLISHED   = 'published';
    public const STATUS_UNPUBLISHED = 'unpublished';

    public const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHED,
        self::STATUS_UNPUBLISHED,
    ];

    public const ALLOWED_CONTENT_TYPES = [
        'movie',
        'series',
        'episode',
        'song',
    ];

    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    public static function getTableForType(string $contentType): ?string
    {
        return match ($contentType) {
            'movie'   => 'multimedia_movies',
            'series'  => 'multimedia_series',
            'episode' => 'multimedia_episodes',
            'song'    => 'multimedia_songs',
            default   => null,
        };
    }

    public static function getModelClassForType(string $contentType): ?string
    {
        return match ($contentType) {
            'movie'   => Movie::class,
            'series'  => Series::class,
            'episode' => Episode::class,
            'song'    => Song::class,
            default   => null,
        };
    }

    // -------------------------------------------------------------
    // Timezone Utilities
    // -------------------------------------------------------------

    public static function getAppTimezone(): string
    {
        try {
            $tz = Setting::get('general', 'timezone', 'UTC');
            if (!empty($tz) && in_array($tz, timezone_identifiers_list(), true)) {
                return $tz;
            }
        } catch (\Throwable) {
        }
        return 'UTC';
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function localToUtc(string $localStr): ?string
    {
        $trimmed = trim($localStr);
        if ($trimmed === '') {
            return null;
        }

        try {
            $appTz = new DateTimeZone(self::getAppTimezone());
            $dt = new DateTime($trimmed, $appTz);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function utcToLocal(?string $utcStr, string $format = 'Y-m-d H:i:s'): string
    {
        if (!$utcStr || trim($utcStr) === '') {
            return '';
        }

        try {
            $dt = new DateTime(trim($utcStr), new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone(self::getAppTimezone()));
            return $dt->format($format);
        } catch (\Throwable) {
            return $utcStr;
        }
    }

    // -------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------

    public static function canManageReleases(mixed $user): bool
    {
        if (!$user) {
            return false;
        }

        if (is_object($user)) {
            if (method_exists($user, 'hasRole') && ($user->hasRole('super-admin') || $user->hasRole('admin'))) {
                return true;
            }
            if (method_exists($user, 'can') && $user->can(MultimediaPermission::VIEW)) {
                return true;
            }
            if (!empty($user->role) && in_array($user->role, ['admin', 'administrator', 'super-admin'], true)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------
    // Release Readiness Validation
    // -------------------------------------------------------------

    public static function validateReadiness(string $contentType, object|int $modelOrId): array
    {
        if (is_int($modelOrId) || is_numeric($modelOrId)) {
            $modelClass = self::getModelClassForType($contentType);
            $model = $modelClass ? $modelClass::find((int)$modelOrId) : null;
            if (!$model) {
                return ['ready' => false, 'valid' => false, 'error' => 'Content item not found.', 'errors' => ['Content item not found.']];
            }
        } else {
            $model = $modelOrId;
        }

        $errors = [];

        // 1. Basic title check
        $title = trim((string)($model->title ?? ''));
        if ($title === '') {
            $errors[] = 'Title cannot be empty.';
        }

        // 2. Playable media check for Movie, Episode, Song
        if (in_array($contentType, ['movie', 'episode', 'song'], true)) {
            $sources = MediaSource::getForContent($contentType, (int)$model->id, true);
            if (empty($sources)) {
                $errors[] = 'Content must have at least one active media source before publication.';
            }
        }

        // 3. Parent hierarchy check for Episode
        if ($contentType === 'episode' && $model instanceof Episode) {
            $series = $model->getSeries();
            if (!$series) {
                $errors[] = 'Episode must belong to a valid series.';
            } elseif (($series->status ?? '') !== self::STATUS_PUBLISHED) {
                $errors[] = 'Parent series is not published. Parent series must be published before releasing episodes.';
            }

            $season = $model->getSeason();
            if (!$season) {
                $errors[] = 'Episode must belong to a valid season.';
            }
        }

        return [
            'ready'  => empty($errors),
            'valid'  => empty($errors),
            'error'  => !empty($errors) ? implode(' ', $errors) : null,
            'errors' => $errors,
        ];
    }

    // -------------------------------------------------------------
    // Editorial State Transitions
    // -------------------------------------------------------------

    /**
     * Publish an item immediately.
     */
    public static function publishNow(mixed $arg1, mixed $arg2 = null, mixed $arg3 = null): array
    {
        if (is_string($arg1) && in_array($arg1, self::ALLOWED_CONTENT_TYPES, true)) {
            $contentType = $arg1;
            $id = (int)$arg2;
            $user = $arg3 ?? current_user();
        } else {
            $user = $arg1;
            $contentType = (string)$arg2;
            $id = (int)$arg3;
        }

        if (!self::canManageReleases($user)) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'Unauthorized to publish content.'];
        }

        $table = self::getTableForType($contentType);
        $modelClass = self::getModelClassForType($contentType);
        if (!$table || !$modelClass) {
            return ['success' => false, 'error' => 'Invalid content type.'];
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return ['success' => false, 'error' => 'Content not found.'];
        }

        // Validate release readiness
        $readiness = self::validateReadiness($contentType, $model);
        if (!$readiness['ready']) {
            return [
                'success' => false,
                'error'   => implode(' ', $readiness['errors']),
                'errors'  => $readiness['errors'],
            ];
        }

        $now = self::nowUtc();
        $db = self::getDb();

        $db->update($table, [
            'status'       => self::STATUS_PUBLISHED,
            'published_at' => $now,
            'publish_at'   => null,
            'updated_at'   => $now,
        ], ['id' => $id]);

        // Trigger Phase 8 notifications
        self::triggerReleaseNotification($contentType, $id);

        Logger::info("Favorite Multimedia: Item published immediately", [
            'content_type' => $contentType,
            'id'           => $id,
            'published_at' => $now,
            'user_id'      => is_object($user) ? ($user->id ?? 0) : (int)$user,
        ]);

        return [
            'success'      => true,
            'status'       => self::STATUS_PUBLISHED,
            'published_at' => $now,
        ];
    }

    /**
     * Schedule an item for future release.
     */
    public static function scheduleItem(
        mixed $arg1,
        mixed $arg2,
        mixed $arg3 = null,
        mixed $arg4 = null,
        mixed $arg5 = null
    ): array {
        if (is_string($arg1) && in_array($arg1, self::ALLOWED_CONTENT_TYPES, true)) {
            $contentType = $arg1;
            $id = (int)$arg2;
            $publishAtUtc = (string)$arg3;
            $unpublishAtUtc = (is_string($arg4) && $arg4 !== '') ? $arg4 : null;
            $user = $arg5 ?? (is_object($arg4) ? $arg4 : current_user());
        } else {
            $user = $arg1;
            $contentType = (string)$arg2;
            $id = (int)$arg3;
            $publishAtUtc = (string)$arg4;
            $unpublishAtUtc = (is_string($arg5) && $arg5 !== '') ? $arg5 : null;
        }

        if (!self::canManageReleases($user)) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'Unauthorized to schedule content.'];
        }

        $table = self::getTableForType($contentType);
        $modelClass = self::getModelClassForType($contentType);
        if (!$table || !$modelClass) {
            return ['success' => false, 'error' => 'Invalid content type.'];
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return ['success' => false, 'error' => 'Content not found.'];
        }

        $nowUtc = self::nowUtc();

        // Validate timestamp format and future requirement
        $pubTs = strtotime($publishAtUtc);
        if ($pubTs === false) {
            return ['success' => false, 'error' => 'Invalid scheduled release date/time.'];
        }

        if ($publishAtUtc <= $nowUtc) {
            return [
                'success' => false,
                'error'   => 'Scheduled release time must be in the future. To release now, use Publish Now.',
            ];
        }

        if ($unpublishAtUtc !== null) {
            $unpubTs = strtotime($unpublishAtUtc);
            if ($unpubTs === false || $unpublishAtUtc <= $publishAtUtc) {
                return [
                    'success' => false,
                    'error'   => 'Scheduled unpublish time must be later than the release time.',
                ];
            }
        }

        $db = self::getDb();
        $db->update($table, [
            'status'       => self::STATUS_SCHEDULED,
            'publish_at'   => $publishAtUtc,
            'unpublish_at' => $unpublishAtUtc,
            'updated_at'   => $nowUtc,
        ], ['id' => $id]);

        Logger::info("Favorite Multimedia: Item scheduled for release", [
            'content_type' => $contentType,
            'id'           => $id,
            'publish_at'   => $publishAtUtc,
            'unpublish_at' => $unpublishAtUtc,
            'user_id'      => is_object($user) ? ($user->id ?? 0) : (int)$user,
        ]);

        return [
            'success'      => true,
            'status'       => self::STATUS_SCHEDULED,
            'publish_at'   => $publishAtUtc,
            'unpublish_at' => $unpublishAtUtc,
        ];
    }

    /**
     * Cancel scheduled release (revert to draft).
     */
    public static function cancelSchedule(mixed $arg1, mixed $arg2 = null, mixed $arg3 = null): array
    {
        if (is_string($arg1) && in_array($arg1, self::ALLOWED_CONTENT_TYPES, true)) {
            $contentType = $arg1;
            $id = (int)$arg2;
            $user = $arg3 ?? current_user();
        } else {
            $user = $arg1;
            $contentType = (string)$arg2;
            $id = (int)$arg3;
        }

        if (!self::canManageReleases($user)) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'Unauthorized to cancel release schedule.'];
        }

        $table = self::getTableForType($contentType);
        $modelClass = self::getModelClassForType($contentType);
        if (!$table || !$modelClass) {
            return ['success' => false, 'error' => 'Invalid content type.'];
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return ['success' => false, 'error' => 'Content not found.'];
        }

        $now = self::nowUtc();
        $db = self::getDb();
        $db->update($table, [
            'status'       => self::STATUS_DRAFT,
            'publish_at'   => null,
            'unpublish_at' => null,
            'updated_at'   => $now,
        ], ['id' => $id]);

        Logger::info("Favorite Multimedia: Release schedule cancelled, returned to draft", [
            'content_type' => $contentType,
            'id'           => $id,
            'user_id'      => is_object($user) ? ($user->id ?? 0) : (int)$user,
        ]);

        return [
            'success' => true,
            'status'  => self::STATUS_DRAFT,
        ];
    }

    /**
     * Unpublish content (take offline without deleting).
     */
    public static function unpublishItem(mixed $arg1, mixed $arg2 = null, mixed $arg3 = null): array
    {
        if (is_string($arg1) && in_array($arg1, self::ALLOWED_CONTENT_TYPES, true)) {
            $contentType = $arg1;
            $id = (int)$arg2;
            $user = $arg3 ?? current_user();
        } else {
            $user = $arg1;
            $contentType = (string)$arg2;
            $id = (int)$arg3;
        }

        if (!self::canManageReleases($user)) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'Unauthorized to unpublish content.'];
        }

        $table = self::getTableForType($contentType);
        $modelClass = self::getModelClassForType($contentType);
        if (!$table || !$modelClass) {
            return ['success' => false, 'error' => 'Invalid content type.'];
        }

        $model = $modelClass::find($id);
        if (!$model) {
            return ['success' => false, 'error' => 'Content not found.'];
        }

        $now = self::nowUtc();
        $db = self::getDb();
        $db->update($table, [
            'status'       => self::STATUS_UNPUBLISHED,
            'unpublish_at' => null,
            'updated_at'   => $now,
        ], ['id' => $id]);

        Logger::info("Favorite Multimedia: Content unpublished", [
            'content_type' => $contentType,
            'id'           => $id,
            'user_id'      => is_object($user) ? ($user->id ?? 0) : (int)$user,
        ]);

        return [
            'success' => true,
            'status'  => self::STATUS_UNPUBLISHED,
        ];
    }

    // -------------------------------------------------------------
    // Automated Release Engine
    // -------------------------------------------------------------

    /**
     * Process all due scheduled releases and unpublishing jobs.
     * Guaranteed to be idempotent and timezone-safe.
     */
    public static function processDueReleases(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $now = self::nowUtc();
        $db = self::getDb();

        $publishedCount = 0;
        $unpublishedCount = 0;
        $failedCount = 0;
        $processed = [];
        $errors = [];

        foreach (self::ALLOWED_CONTENT_TYPES as $type) {
            if (($publishedCount + $unpublishedCount) >= $limit) {
                break;
            }

            $table = self::getTableForType($type);
            $modelClass = self::getModelClassForType($type);
            if (!$table || !$modelClass) {
                continue;
            }

            $batchRemaining = $limit - ($publishedCount + $unpublishedCount);

            // 1. Process Due Scheduled Publications
            $dueSql = "SELECT * FROM `{$table}` 
                       WHERE `status` = ? AND `publish_at` IS NOT NULL AND `publish_at` <= ? 
                       ORDER BY `publish_at` ASC LIMIT ?";
            $dueRows = $db->select($dueSql, [self::STATUS_SCHEDULED, $now, $batchRemaining]);

            foreach ($dueRows as $row) {
                if (($publishedCount + $unpublishedCount) >= $limit) {
                    break;
                }

                $id = (int)$row->id;
                $model = new $modelClass((array)$row);

                // Readiness check
                $readiness = self::validateReadiness($type, $model);
                if (!$readiness['ready']) {
                    $failedCount++;
                    $errors[] = [
                        'content_type' => $type,
                        'content_id'   => $id,
                        'error'        => implode(' ', $readiness['errors']),
                        'errors'       => $readiness['errors'],
                    ];
                    Logger::warning("Favorite Multimedia: Scheduled release readiness failed for {$type} #{$id}", [
                        'type'   => $type,
                        'id'     => $id,
                        'errors' => $readiness['errors'],
                    ]);
                    continue;
                }

                // Atomic state transition: WHERE status = 'scheduled' ensures exact idempotency
                $affected = $db->update($table, [
                    'status'       => self::STATUS_PUBLISHED,
                    'published_at' => $now,
                    'publish_at'   => null,
                    'updated_at'   => $now,
                ], [
                    'id'     => $id,
                    'status' => self::STATUS_SCHEDULED,
                ]);

                if ($affected > 0) {
                    $publishedCount++;
                    $processed[] = [
                        'type'   => $type,
                        'id'     => $id,
                        'action' => 'published',
                    ];

                    try {
                        self::triggerReleaseNotification($type, $id);
                    } catch (\Throwable $ne) {
                        Logger::error("Favorite Multimedia: Notification dispatch failed for scheduled release {$type} #{$id}", [
                            'error' => $ne->getMessage(),
                        ]);
                    }

                    Logger::info("Favorite Multimedia: Scheduled item published automatically", [
                        'content_type' => $type,
                        'id'           => $id,
                        'published_at' => $now,
                    ]);
                }
            }

            if (($publishedCount + $unpublishedCount) >= $limit) {
                break;
            }

            $unpubBatchRemaining = $limit - ($publishedCount + $unpublishedCount);

            // 2. Process Due Scheduled Unpublishings
            $unpubSql = "SELECT * FROM `{$table}` 
                         WHERE `status` = ? AND `unpublish_at` IS NOT NULL AND `unpublish_at` <= ? 
                         ORDER BY `unpublish_at` ASC LIMIT ?";
            $unpubRows = $db->select($unpubSql, [self::STATUS_PUBLISHED, $now, $unpubBatchRemaining]);

            foreach ($unpubRows as $row) {
                if (($publishedCount + $unpublishedCount) >= $limit) {
                    break;
                }

                $id = (int)$row->id;
                $affected = $db->update($table, [
                    'status'       => self::STATUS_UNPUBLISHED,
                    'unpublish_at' => null,
                    'updated_at'   => $now,
                ], [
                    'id'     => $id,
                    'status' => self::STATUS_PUBLISHED,
                ]);

                if ($affected > 0) {
                    $unpublishedCount++;
                    $processed[] = [
                        'type'   => $type,
                        'id'     => $id,
                        'action' => 'unpublished',
                    ];

                    Logger::info("Favorite Multimedia: Item unpublished automatically", [
                        'content_type' => $type,
                        'id'           => $id,
                        'unpublish_at' => $now,
                    ]);
                }
            }
        }

        return [
            'success'           => true,
            'published'         => $publishedCount,
            'published_count'   => $publishedCount,
            'unpublished'       => $unpublishedCount,
            'unpublished_count' => $unpublishedCount,
            'failed'            => $failedCount,
            'failed_count'      => $failedCount,
            'errors'            => $errors,
            'processed'         => count($processed),
            'processed_count'   => count($processed),
            'processed_items'   => $processed,
            'timestamp_utc'     => $now,
        ];
    }

    /**
     * Trigger Phase 8 notification fan-out for released content.
     */
    public static function triggerReleaseNotification(string $contentType, int $id): int
    {
        if ($contentType === 'episode') {
            return MultimediaNotificationService::onEpisodePublished($id);
        }

        if ($contentType === 'song') {
            return MultimediaNotificationService::onSongPublished($id);
        }

        return 0;
    }

    // -------------------------------------------------------------
    // Queue & Calendar Queries
    // -------------------------------------------------------------

    /**
     * Get scheduled release queue items with pagination.
     */
    public static function getScheduledQueue(array|int $limitOrFilters = 50, int $offset = 0, ?string $contentType = null): array
    {
        $limit = 50;
        $statusFilter = self::STATUS_SCHEDULED;

        if (is_array($limitOrFilters)) {
            $limit = (int)($limitOrFilters['limit'] ?? 50);
            $offset = (int)($limitOrFilters['offset'] ?? 0);
            $contentType = $limitOrFilters['content_type'] ?? null;
            $statusFilter = $limitOrFilters['status'] ?? self::STATUS_SCHEDULED;
        } else {
            $limit = (int)$limitOrFilters;
        }

        $db = self::getDb();
        $types = ($contentType && in_array($contentType, self::ALLOWED_CONTENT_TYPES, true))
            ? [$contentType]
            : self::ALLOWED_CONTENT_TYPES;

        $items = [];
        $now = self::nowUtc();

        foreach ($types as $type) {
            $table = self::getTableForType($type);
            $modelClass = self::getModelClassForType($type);
            if (!$table || !$modelClass) {
                continue;
            }

            $whereStatus = "WHERE `status` = ?";
            $params = [$statusFilter];
            if ($statusFilter === 'all' || empty($statusFilter)) {
                $whereStatus = "WHERE `status` IN ('scheduled', 'draft', 'published', 'unpublished')";
                $params = [];
            }

            $rows = $db->select(
                "SELECT * FROM `{$table}` {$whereStatus} ORDER BY COALESCE(`publish_at`, `created_at`) ASC, id ASC LIMIT ?",
                array_merge($params, [$limit])
            );

            foreach ($rows as $r) {
                $model = new $modelClass((array)$r);
                $readiness = self::validateReadiness($type, $model);
                $isOverdue = !empty($r->publish_at) && ($r->publish_at <= $now);

                $items[] = [
                    'id'               => (int)$r->id,
                    'content_type'     => $type,
                    'title'            => (string)$r->title,
                    'slug'             => (string)($r->slug ?? ''),
                    'access_mode'      => (string)($r->access_mode ?? 'public'),
                    'status'           => (string)$r->status,
                    'publish_at'       => $r->publish_at ?? null,
                    'publish_at_utc'   => (string)($r->publish_at ?? ''),
                    'publish_at_local' => self::utcToLocal((string)($r->publish_at ?? '')),
                    'unpublish_at'     => $r->unpublish_at ?? null,
                    'unpublish_at_local' => self::utcToLocal((string)($r->unpublish_at ?? '')),
                    'is_overdue'       => $isOverdue,
                    'is_ready'         => $readiness['ready'],
                    'readiness_errors' => $readiness['errors'],
                    'edit_url'         => self::getAdminEditUrl($type, (int)$r->id),
                ];
            }
        }

        // Sort combined queue by publish_at ASC
        usort($items, function($a, $b) {
            $timeA = $a['publish_at_utc'] ?: '9999-99-99';
            $timeB = $b['publish_at_utc'] ?: '9999-99-99';
            return strcmp($timeA, $timeB);
        });

        $total = count($items);
        $sliced = array_slice($items, $offset, $limit);

        return $sliced;
    }

    /**
     * Get releases for calendar view within a date range.
     */
    public static function getCalendarReleases(string $startUtc, string $endUtc, mixed $filterOrType = null): array
    {
        $db = self::getDb();
        $contentType = null;
        $statusFilter = null;

        if (is_array($filterOrType)) {
            $contentType = $filterOrType['content_type'] ?? null;
            $statusFilter = $filterOrType['status'] ?? null;
        } elseif (is_string($filterOrType) && $filterOrType !== '') {
            $contentType = $filterOrType;
        }

        $types = ($contentType && in_array($contentType, self::ALLOWED_CONTENT_TYPES, true))
            ? [$contentType]
            : self::ALLOWED_CONTENT_TYPES;

        $events = [];

        foreach ($types as $type) {
            $table = self::getTableForType($type);
            if (!$table) {
                continue;
            }

            $whereStatus = "";
            $statusParams = [];
            if ($statusFilter && in_array($statusFilter, self::ALLOWED_STATUSES, true)) {
                $whereStatus = " AND `status` = ?";
                $statusParams = [$statusFilter];
            }

            // Fetch scheduled and published items within range
            $sql = "SELECT id, title, slug, status, access_mode, publish_at, published_at 
                    FROM `{$table}` 
                    WHERE ((`publish_at` BETWEEN ? AND ?) 
                       OR (`published_at` BETWEEN ? AND ?))
                       {$whereStatus}
                    ORDER BY COALESCE(publish_at, published_at) ASC";
            $params = array_merge([$startUtc, $endUtc, $startUtc, $endUtc], $statusParams);
            $rows = $db->select($sql, $params);

            foreach ($rows as $r) {
                $timeUtc = (string)($r->publish_at ?: $r->published_at);
                $events[] = [
                    'id'           => (int)$r->id,
                    'content_type' => $type,
                    'title'        => (string)$r->title,
                    'slug'         => (string)($r->slug ?? ''),
                    'status'       => (string)$r->status,
                    'access_mode'  => (string)($r->access_mode ?? 'public'),
                    'time_utc'     => $timeUtc,
                    'time_local'   => self::utcToLocal($timeUtc),
                    'date_local'   => self::utcToLocal($timeUtc, 'Y-m-d'),
                    'publish_at_local' => self::utcToLocal($r->publish_at),
                    'published_at_local' => self::utcToLocal($r->published_at),
                    'edit_url'     => self::getAdminEditUrl($type, (int)$r->id),
                ];
            }
        }

        usort($events, fn($a, $b) => strcmp($a['time_utc'], $b['time_utc']));

        return $events;
    }

    /**
     * Get dashboard operational metrics for release scheduling.
     */
    public static function getOperationalMetrics(): array
    {
        $db = self::getDb();
        $now = self::nowUtc();
        $todayStart = gmdate('Y-m-d 00:00:00');

        $scheduledTotal = 0;
        $overdueTotal = 0;
        $publishedToday = 0;

        foreach (self::ALLOWED_CONTENT_TYPES as $type) {
            $table = self::getTableForType($type);
            if (!$table) {
                continue;
            }

            $sRow = $db->selectOne("SELECT COUNT(*) as c FROM `{$table}` WHERE `status` = ?", [self::STATUS_SCHEDULED]);
            $scheduledTotal += (int)($sRow->c ?? 0);

            $oRow = $db->selectOne("SELECT COUNT(*) as c FROM `{$table}` WHERE `status` = ? AND `publish_at` <= ?", [self::STATUS_SCHEDULED, $now]);
            $overdueTotal += (int)($oRow->c ?? 0);

            $pRow = $db->selectOne("SELECT COUNT(*) as c FROM `{$table}` WHERE `status` = ? AND `published_at` >= ?", [self::STATUS_PUBLISHED, $todayStart]);
            $publishedToday += (int)($pRow->c ?? 0);
        }

        return [
            'scheduled_total' => $scheduledTotal,
            'overdue_total'   => $overdueTotal,
            'published_today' => $publishedToday,
            'app_timezone'    => self::getAppTimezone(),
        ];
    }

    private static function getAdminEditUrl(string $contentType, int $id): string
    {
        return match ($contentType) {
            'movie'   => "/admin/multimedia-movies?edit={$id}",
            'series'  => "/admin/multimedia-series?edit={$id}",
            'episode' => "/admin/multimedia-episodes?edit={$id}",
            'song'    => "/admin/multimedia-songs?edit={$id}",
            default   => '#',
        };
    }
}

