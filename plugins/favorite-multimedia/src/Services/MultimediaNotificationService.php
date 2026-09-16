<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Logger;
use FavoriteCMS\Multimedia\Models\Artist;
use FavoriteCMS\Multimedia\Models\Episode;
use FavoriteCMS\Multimedia\Models\Movie;
use FavoriteCMS\Multimedia\Models\MultimediaComment;
use FavoriteCMS\Multimedia\Models\MultimediaNotification;
use FavoriteCMS\Multimedia\Models\MultimediaNotificationPreference;
use FavoriteCMS\Multimedia\Models\MultimediaReview;
use FavoriteCMS\Multimedia\Models\MultimediaSubscription;
use FavoriteCMS\Multimedia\Models\Playlist;
use FavoriteCMS\Multimedia\Models\Series;
use FavoriteCMS\Multimedia\Models\Song;

class MultimediaNotificationService
{
    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    public static function hasNotificationsTable(): bool
    {
        try {
            $db = self::getDb();
            return method_exists($db, 'tableExists') ? $db->tableExists('multimedia_notifications') : true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasPreferencesTable(): bool
    {
        try {
            $db = self::getDb();
            return method_exists($db, 'tableExists') ? $db->tableExists('multimedia_notification_preferences') : true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Dispatch notification to a user with preference check and deduplication.
     */
    public static function notify(
        int $userId,
        string $type,
        string $subjectType,
        int $subjectId,
        ?int $actorUserId,
        string $title,
        string $message,
        string $dedupeKey
    ): bool {
        if ($userId <= 0 || !self::hasNotificationsTable()) {
            return false;
        }

        // Check user preferences
        if (!self::isNotificationAllowedByPreference($userId, $type)) {
            return false;
        }

        try {
            $record = MultimediaNotification::createSafe(
                $userId,
                $type,
                $subjectType,
                $subjectId,
                $actorUserId,
                $dedupeKey,
                $title,
                $message
            );
            return $record !== null;
        } catch (\Throwable $e) {
            Logger::error('Favorite Multimedia: Failed to dispatch notification', [
                'user_id' => $userId,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if a notification type is allowed by user's delivery preferences.
     */
    public static function isNotificationAllowedByPreference(int $userId, string $type): bool
    {
        if (!self::hasPreferencesTable()) {
            return true;
        }

        return match ($type) {
            MultimediaNotification::TYPE_NEW_EPISODE,
            MultimediaNotification::TYPE_NEW_ARTIST_SONG,
            MultimediaNotification::TYPE_PLAYLIST_UPDATED =>
                MultimediaNotificationPreference::allowsContentUpdates($userId),

            MultimediaNotification::TYPE_COMMENT_REPLY =>
                MultimediaNotificationPreference::allowsEngagementReplies($userId),

            MultimediaNotification::TYPE_REVIEW_APPROVED,
            MultimediaNotification::TYPE_REVIEW_REJECTED,
            MultimediaNotification::TYPE_COMMENT_APPROVED,
            MultimediaNotification::TYPE_COMMENT_REJECTED =>
                MultimediaNotificationPreference::allowsModerationUpdates($userId),

            default => true,
        };
    }

    /**
     * Batch fan-out release notifications to subscribers of a target.
     */
    public static function fanOutContentNotification(
        string $targetType,
        int $targetId,
        string $notifType,
        string $subjectType,
        int $subjectId,
        string $title,
        string $message,
        string $eventPrefix
    ): int {
        if (!MultimediaSubscriptionService::hasSubscriptionsTable() || !self::hasNotificationsTable()) {
            return 0;
        }

        $batchSize = 500;
        $offset = 0;
        $delivered = 0;

        while (true) {
            $recipients = MultimediaSubscription::getSubscribers($targetType, $targetId, $batchSize, $offset);
            if (empty($recipients)) {
                break;
            }

            foreach ($recipients as $recipientId) {
                $dedupeKey = "{$eventPrefix}_{$subjectId}_u_{$recipientId}";
                if (self::notify($recipientId, $notifType, $subjectType, $subjectId, null, $title, $message, $dedupeKey)) {
                    $delivered++;
                }
            }

            if (count($recipients) < $batchSize) {
                break;
            }
            $offset += $batchSize;
        }

        return $delivered;
    }

    // -------------------------------------------------------------
    // Event Triggers
    // -------------------------------------------------------------

    /**
     * Triggered when an episode is newly published.
     */
    public static function onEpisodePublished(int $episodeId): int
    {
        if ($episodeId <= 0) {
            return 0;
        }

        $episode = Episode::find($episodeId);
        if (!$episode || $episode->status !== 'published') {
            return 0;
        }

        $series = $episode->getSeries();
        if (!$series) {
            return 0;
        }

        $seriesTitle = (string)$series->title;
        $epTitle = (string)$episode->title;
        $epNum = (int)$episode->episode_number;

        $title = "New Episode: {$seriesTitle}";
        $message = "Episode {$epNum} ({$epTitle}) is now available to watch.";

        return self::fanOutContentNotification(
            MultimediaSubscription::TARGET_SERIES,
            (int)$series->id,
            MultimediaNotification::TYPE_NEW_EPISODE,
            'episode',
            $episodeId,
            $title,
            $message,
            'ep_pub'
        );
    }

    /**
     * Triggered when a song is newly published.
     */
    public static function onSongPublished(int $songId): int
    {
        if ($songId <= 0) {
            return 0;
        }

        $song = Song::find($songId);
        if (!$song || $song->status !== 'published') {
            return 0;
        }

        $artistId = (int)($song->artist_id ?? 0);
        if ($artistId <= 0) {
            return 0;
        }

        $artist = Artist::find($artistId);
        $artistName = $artist ? (string)$artist->name : 'Artist';
        $songTitle = (string)$song->title;

        $title = "New Song by {$artistName}";
        $message = "{$artistName} just released a new track: {$songTitle}.";

        return self::fanOutContentNotification(
            MultimediaSubscription::TARGET_ARTIST,
            $artistId,
            MultimediaNotification::TYPE_NEW_ARTIST_SONG,
            'song',
            $songId,
            $title,
            $message,
            'song_pub'
        );
    }

    /**
     * Notify followers of one or more artists about a newly published song.
     */
    public static function notifyArtistFollowersNewSong(int $songId, string $songTitle, array $artistIds): int
    {
        if ($songId <= 0 || empty($artistIds)) {
            return 0;
        }

        $artists = [];
        foreach ($artistIds as $aid) {
            $a = Artist::find((int)$aid);
            if ($a) {
                $artists[] = (string)$a->name;
            }
        }
        $artistNames = !empty($artists) ? implode(', ', $artists) : 'Artist';

        $title = "New Song by {$artistNames}";
        $message = "{$artistNames} just released a new track: {$songTitle}.";

        $delivered = 0;
        $notifiedUsers = [];

        foreach ($artistIds as $aid) {
            $aid = (int)$aid;
            if ($aid <= 0) {
                continue;
            }

            $offset = 0;
            $batchSize = 500;
            while (true) {
                $recipients = MultimediaSubscription::getSubscribers(MultimediaSubscription::TARGET_ARTIST, $aid, $batchSize, $offset);
                if (empty($recipients)) {
                    break;
                }

                foreach ($recipients as $recipientId) {
                    if (isset($notifiedUsers[$recipientId])) {
                        continue;
                    }
                    $dedupeKey = "song_pub_{$songId}_u_{$recipientId}";
                    if (self::notify($recipientId, MultimediaNotification::TYPE_NEW_ARTIST_SONG, 'song', $songId, null, $title, $message, $dedupeKey)) {
                        $delivered++;
                    }
                    $notifiedUsers[$recipientId] = true;
                }

                if (count($recipients) < $batchSize) {
                    break;
                }
                $offset += $batchSize;
            }
        }

        return $delivered;
    }

    /**
     * Triggered when a playlist is updated with new tracks.
     */
    public static function onPlaylistUpdated(int $playlistId): int
    {
        if ($playlistId <= 0) {
            return 0;
        }

        $playlist = Playlist::find($playlistId);
        if (!$playlist || $playlist->status !== 'published') {
            return 0;
        }

        $plTitle = (string)$playlist->title;
        $title = "Playlist Updated: {$plTitle}";
        $message = "New tracks have been added to {$plTitle}.";

        // Dedupe key includes current date so subsequent tracks on the same day don't duplicate spam
        $dayStamp = date('Ymd');

        return self::fanOutContentNotification(
            MultimediaSubscription::TARGET_PLAYLIST,
            $playlistId,
            MultimediaNotification::TYPE_PLAYLIST_UPDATED,
            'playlist',
            $playlistId,
            $title,
            $message,
            "pl_up_{$dayStamp}"
        );
    }

    /**
     * Triggered when User B replies to User A's comment.
     */
    public static function onCommentReplied(MultimediaComment $parentComment, MultimediaComment $replyComment): bool
    {
        $parentAuthorId = (int)$parentComment->user_id;
        $replyAuthorId = (int)$replyComment->user_id;

        // Self-reply suppression
        if ($parentAuthorId <= 0 || $replyAuthorId <= 0 || $parentAuthorId === $replyAuthorId) {
            return false;
        }

        $authorDisplay = MultimediaEngagementService::formatAuthorDisplay($replyAuthorId);
        $authorName = $authorDisplay['display_name'] ?? 'A member';

        $title = "New reply from {$authorName}";
        $snippet = mb_substr(strip_tags((string)$replyComment->body), 0, 100, 'UTF-8');
        $message = "{$authorName} replied: \"{$snippet}\"";

        $dedupeKey = "com_reply_{$parentComment->id}_{$replyComment->id}";

        return self::notify(
            $parentAuthorId,
            MultimediaNotification::TYPE_COMMENT_REPLY,
            'comment',
            (int)$replyComment->id,
            $replyAuthorId,
            $title,
            $message,
            $dedupeKey
        );
    }

    /**
     * Triggered when User B replies to User A's comment (by IDs).
     */
    public static function notifyCommentReply(int $parentCommentId, int $replyCommentId, int $actorUserId = 0, string $contentSnippet = ''): bool
    {
        $parentComment = MultimediaComment::find($parentCommentId);
        $replyComment = MultimediaComment::find($replyCommentId);
        if (!$parentComment || !$replyComment) {
            return false;
        }
        return self::onCommentReplied($parentComment, $replyComment);
    }

    /**
     * Triggered when review moderation status changes.
     */
    public static function onReviewModerated(MultimediaReview $review, string $oldStatus, string $newStatus): bool
    {
        if ($oldStatus === $newStatus) {
            return false;
        }

        $authorId = (int)$review->user_id;
        if ($authorId <= 0) {
            return false;
        }

        if ($newStatus === MultimediaReview::STATUS_APPROVED) {
            $type = MultimediaNotification::TYPE_REVIEW_APPROVED;
            $title = "Your review was approved";
            $message = "Your review has been approved and is now public.";
        } elseif ($newStatus === MultimediaReview::STATUS_REJECTED) {
            $type = MultimediaNotification::TYPE_REVIEW_REJECTED;
            $title = "Your review was not approved";
            $message = "Your review did not meet our community guidelines.";
        } else {
            return false;
        }

        $dedupeKey = "rev_mod_{$review->id}_{$newStatus}";

        return self::notify(
            $authorId,
            $type,
            'review',
            (int)$review->id,
            null,
            $title,
            $message,
            $dedupeKey
        );
    }

    /**
     * Triggered when comment moderation status changes.
     */
    public static function onCommentModerated(MultimediaComment $comment, string $oldStatus, string $newStatus): bool
    {
        if ($oldStatus === $newStatus) {
            return false;
        }

        $authorId = (int)$comment->user_id;
        if ($authorId <= 0) {
            return false;
        }

        if ($newStatus === MultimediaComment::STATUS_APPROVED) {
            $type = MultimediaNotification::TYPE_COMMENT_APPROVED;
            $title = "Your comment was approved";
            $message = "Your comment has been approved and is now visible.";
        } elseif ($newStatus === MultimediaComment::STATUS_REJECTED) {
            $type = MultimediaNotification::TYPE_COMMENT_REJECTED;
            $title = "Your comment was not approved";
            $message = "Your comment was removed by moderation.";
        } else {
            return false;
        }

        $dedupeKey = "com_mod_{$comment->id}_{$newStatus}";

        return self::notify(
            $authorId,
            $type,
            'comment',
            (int)$comment->id,
            null,
            $title,
            $message,
            $dedupeKey
        );
    }

    // -------------------------------------------------------------
    // Inbox & Safe URL Resolution
    // -------------------------------------------------------------

    public static function resolveTargetUrl(string $subjectType, int $subjectId): array
    {
        if ($subjectId <= 0) {
            return ['url' => '#', 'is_available' => false];
        }

        try {
            switch ($subjectType) {
                case 'episode':
                    $ep = Episode::find($subjectId);
                    if ($ep && !empty($ep->slug)) {
                        return ['url' => '/episode/' . urlencode((string)$ep->slug), 'is_available' => true];
                    }
                    break;

                case 'song':
                    $s = Song::find($subjectId);
                    if ($s && !empty($s->slug)) {
                        return ['url' => '/song/' . urlencode((string)$s->slug), 'is_available' => true];
                    }
                    break;

                case 'playlist':
                    $p = Playlist::find($subjectId);
                    if ($p && !empty($p->slug)) {
                        return ['url' => '/playlist/' . urlencode((string)$p->slug), 'is_available' => true];
                    }
                    break;

                case 'review':
                    $r = MultimediaReview::find($subjectId);
                    if ($r && (int)$r->content_id > 0) {
                        return self::resolveContentUrl((string)$r->content_type, (int)$r->content_id);
                    }
                    break;

                case 'comment':
                    $c = MultimediaComment::find($subjectId);
                    if ($c && (int)$c->content_id > 0) {
                        return self::resolveContentUrl((string)$c->content_type, (int)$c->content_id);
                    }
                    break;
            }
        } catch (\Throwable) {
            return ['url' => '#', 'is_available' => false];
        }

        return ['url' => '#', 'is_available' => false];
    }

    public static function resolveContentUrl(string $contentType, int $contentId): array
    {
        return match ($contentType) {
            'movie' => ($m = Movie::find($contentId)) ? ['url' => '/movie/' . urlencode((string)$m->slug), 'is_available' => true] : ['url' => '#', 'is_available' => false],
            'series' => ($s = Series::find($contentId)) ? ['url' => '/series/' . urlencode((string)$s->slug), 'is_available' => true] : ['url' => '#', 'is_available' => false],
            'episode' => ($e = Episode::find($contentId)) ? ['url' => '/episode/' . urlencode((string)$e->slug), 'is_available' => true] : ['url' => '#', 'is_available' => false],
            'song' => ($so = Song::find($contentId)) ? ['url' => '/song/' . urlencode((string)$so->slug), 'is_available' => true] : ['url' => '#', 'is_available' => false],
            'playlist' => ($pl = Playlist::find($contentId)) ? ['url' => '/playlist/' . urlencode((string)$pl->slug), 'is_available' => true] : ['url' => '#', 'is_available' => false],
            default => ['url' => '#', 'is_available' => false],
        };
    }

    public static function getInbox(mixed $user, string $tab = 'all', int $limit = 20, int $offset = 0): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasNotificationsTable()) {
            return ['items' => [], 'total' => 0, 'unread_count' => 0];
        }

        $unreadOnly = ($tab === 'unread') ? true : null;
        $items = MultimediaNotification::getForUser($userId, $limit, $offset, $unreadOnly);
        $total = MultimediaNotification::countForUser($userId, $unreadOnly);
        $unreadCount = MultimediaNotification::countUnread($userId);

        $mapped = array_map(function (MultimediaNotification $n) {
            $obj = new \stdClass();
            $obj->id = (int)$n->id;
            $type = (string)$n->type;
            $obj->raw_type = $type;
            $obj->type = ($type === MultimediaNotification::TYPE_NEW_ARTIST_SONG) ? 'new_song' : $type;
            $obj->subject_type = (string)$n->subject_type;
            $obj->subject_id = (int)$n->subject_id;
            $obj->target_id = (int)$n->subject_id;
            $obj->target_type = (string)$n->subject_type;
            $obj->title = (string)$n->title;
            $obj->message = (string)$n->message;
            $obj->is_read = (bool)$n->is_read;
            $obj->created_at = (string)$n->created_at;
            return $obj;
        }, $items);

        return [
            'items'        => $mapped,
            'total'        => $total,
            'unread_count' => $unreadCount,
        ];
    }

    public static function getNotifications(mixed $user, int $limit = 20, int $offset = 0, ?bool $unreadOnly = null): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasNotificationsTable()) {
            return ['notifications' => [], 'total' => 0, 'unread_count' => 0];
        }

        $items = MultimediaNotification::getForUser($userId, $limit, $offset, $unreadOnly);
        $total = MultimediaNotification::countForUser($userId, $unreadOnly);
        $unreadCount = MultimediaNotification::countUnread($userId);

        $hydrated = array_map(function (MultimediaNotification $n) {
            $target = self::resolveTargetUrl((string)$n->subject_type, (int)$n->subject_id);
            $actor = $n->actor_user_id ? MultimediaEngagementService::formatAuthorDisplay((int)$n->actor_user_id) : null;

            return [
                'id'           => (int)$n->id,
                'type'         => (string)$n->type,
                'subject_type' => (string)$n->subject_type,
                'subject_id'   => (int)$n->subject_id,
                'title'        => htmlspecialchars((string)$n->title, ENT_QUOTES, 'UTF-8'),
                'message'      => htmlspecialchars((string)$n->message, ENT_QUOTES, 'UTF-8'),
                'is_read'      => (bool)$n->is_read,
                'created_at'   => (string)$n->created_at,
                'time_ago'     => self::timeAgo((string)$n->created_at),
                'target_url'   => $target['url'],
                'is_available' => $target['is_available'],
                'actor'        => $actor,
            ];
        }, $items);

        return [
            'notifications' => $hydrated,
            'total'         => $total,
            'unread_count'  => $unreadCount,
            'unread_badge'  => $unreadCount > 99 ? '99+' : (string)$unreadCount,
        ];
    }

    public static function getUnreadCount(mixed $user): int
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasNotificationsTable()) {
            return 0;
        }

        return MultimediaNotification::countUnread($userId);
    }

    public static function markAsRead(mixed $user, int $notificationId): bool
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasNotificationsTable()) {
            return false;
        }

        return MultimediaNotification::markRead($notificationId, $userId);
    }

    public static function getUserNotifications(mixed $user, int $limit = 20, int $offset = 0, ?bool $unreadOnly = null): array
    {
        $res = self::getNotifications($user, $limit, $offset, $unreadOnly);
        return $res['notifications'] ?? [];
    }

    public static function markAllAsRead(mixed $user): int
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasNotificationsTable()) {
            return 0;
        }

        return MultimediaNotification::markAllRead($userId);
    }

    // -------------------------------------------------------------
    // Preferences
    // -------------------------------------------------------------

    public static function getPreferences(mixed $user): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasPreferencesTable()) {
            return [
                'notify_content_updates'    => true,
                'notify_engagement_replies' => true,
                'notify_moderation_updates' => true,
            ];
        }

        return MultimediaNotificationPreference::getForUser($userId);
    }

    public static function savePreferences(mixed $user, array $prefs): bool
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasPreferencesTable()) {
            return false;
        }

        return MultimediaNotificationPreference::saveForUser($userId, $prefs);
    }

    public static function timeAgo(string $datetime): string
    {
        $time = strtotime($datetime);
        if (!$time) {
            return $datetime;
        }

        $diff = time() - $time;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $m = (int)floor($diff / 60);
            return "{$m}m ago";
        }
        if ($diff < 86400) {
            $h = (int)floor($diff / 3600);
            return "{$h}h ago";
        }
        if ($diff < 604800) {
            $d = (int)floor($diff / 86400);
            return "{$d}d ago";
        }

        return date('M j, Y', $time);
    }
}
