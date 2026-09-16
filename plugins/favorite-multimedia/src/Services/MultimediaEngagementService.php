<?php

declare(strict_types=1);

namespace FavoriteCMS\Multimedia\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Multimedia\Models\MultimediaComment;
use FavoriteCMS\Multimedia\Models\MultimediaRating;
use FavoriteCMS\Multimedia\Models\MultimediaReport;
use FavoriteCMS\Multimedia\Models\MultimediaReview;
use FavoriteCMS\Multimedia\Permissions\MultimediaPermission;

class MultimediaEngagementService
{
    private static function getDb(): Database
    {
        return Container::getInstance()->get(Database::class);
    }

    public static function hasRatingsTable(): bool
    {
        try {
            $db = self::getDb();
            return method_exists($db, 'tableExists') ? $db->tableExists('multimedia_ratings') : true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasReviewsTable(): bool
    {
        try {
            $db = self::getDb();
            return method_exists($db, 'tableExists') ? $db->tableExists('multimedia_reviews') : true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasCommentsTable(): bool
    {
        try {
            $db = self::getDb();
            return method_exists($db, 'tableExists') ? $db->tableExists('multimedia_comments') : true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function hasReportsTable(): bool
    {
        try {
            $db = self::getDb();
            return method_exists($db, 'tableExists') ? $db->tableExists('multimedia_reports') : true;
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------
    // Ratings
    // -------------------------------------------------------------

    public static function rateContent(mixed $user, string $contentType, int $contentId, int $rating): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required to rate.'];
        }

        if (!in_array($contentType, MultimediaRating::ALLOWED_TYPES, true)) {
            return ['success' => false, 'error' => 'Invalid content type for rating.'];
        }

        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'error' => 'Rating must be an integer between 1 and 5 stars.'];
        }

        if ($contentId <= 0 || !self::hasRatingsTable()) {
            return ['success' => false, 'error' => 'Invalid content ID or table unavailable.'];
        }

        $ok = MultimediaRating::setRating($userId, $contentType, $contentId, $rating);
        $agg = MultimediaRating::getAggregate($contentType, $contentId);

        return [
            'success'        => $ok,
            'user_rating'    => $rating,
            'average_rating' => $agg['average'],
            'rating_count'   => $agg['count'],
        ];
    }

    public static function removeRating(mixed $user, string $contentType, int $contentId): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'];
        }

        if (!self::hasRatingsTable()) {
            return ['success' => false, 'error' => 'Ratings unavailable.'];
        }

        $ok = MultimediaRating::removeRating($userId, $contentType, $contentId);
        $agg = MultimediaRating::getAggregate($contentType, $contentId);

        return [
            'success'        => $ok,
            'user_rating'    => null,
            'average_rating' => $agg['average'],
            'rating_count'   => $agg['count'],
        ];
    }

    public static function getUserRating(mixed $user, string $contentType, int $contentId): ?int
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0 || !self::hasRatingsTable()) {
            return null;
        }
        return MultimediaRating::getUserRating($userId, $contentType, $contentId);
    }

    public static function getRatingAggregate(string $contentType, int $contentId): array
    {
        if (!self::hasRatingsTable()) {
            return ['average' => 0.0, 'count' => 0];
        }
        return MultimediaRating::getAggregate($contentType, $contentId);
    }

    // -------------------------------------------------------------
    // Reviews
    // -------------------------------------------------------------

    public static function isReviewModerationRequired(): bool
    {
        $mode = Setting::get('multimedia', 'review_moderation_mode', 'auto_approve');
        return $mode === 'require_approval';
    }

    public static function createReview(
        mixed $user,
        string $contentType,
        int $contentId,
        string $body,
        ?string $title = null,
        ?int $rating = null,
        bool $isSpoiler = false
    ): array {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required to review.'];
        }

        if (!in_array($contentType, MultimediaReview::ALLOWED_TYPES, true)) {
            return ['success' => false, 'error' => 'Invalid content type for reviews.'];
        }

        $trimmed = trim($body);
        if (mb_strlen($trimmed, 'UTF-8') < 3) {
            return ['success' => false, 'error' => 'Review body must be at least 3 characters.'];
        }
        if (mb_strlen($trimmed, 'UTF-8') > 3000) {
            return ['success' => false, 'error' => 'Review body cannot exceed 3000 characters.'];
        }

        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            return ['success' => false, 'error' => 'Review star rating must be between 1 and 5.'];
        }

        if (!self::hasReviewsTable()) {
            return ['success' => false, 'error' => 'Reviews unavailable.'];
        }

        $status = self::isReviewModerationRequired() ? MultimediaReview::STATUS_PENDING : MultimediaReview::STATUS_APPROVED;

        $review = MultimediaReview::saveReview(
            $userId,
            $contentType,
            $contentId,
            $trimmed,
            $title,
            $rating,
            $isSpoiler,
            $status
        );

        if (!$review) {
            return ['success' => false, 'error' => 'Failed to save review.'];
        }

        return [
            'success' => true,
            'review'  => self::hydrateReview($review, $user),
            'status'  => $status,
            'message' => ($status === MultimediaReview::STATUS_PENDING)
                ? 'Thank you! Your review has been submitted for moderation.'
                : 'Your review has been published.',
        ];
    }

    public static function updateReview(
        mixed $user,
        int $reviewId,
        string $body,
        ?string $title = null,
        ?int $rating = null,
        bool $isSpoiler = false
    ): array {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'];
        }

        if (!self::hasReviewsTable()) {
            return ['success' => false, 'error' => 'Reviews unavailable.'];
        }

        $review = MultimediaReview::find($reviewId);
        if (!$review) {
            return ['success' => false, 'error' => 'Review not found.'];
        }

        if ((int)$review->user_id !== $userId) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'You can only edit your own review.'];
        }

        $trimmed = trim($body);
        if (mb_strlen($trimmed, 'UTF-8') < 3 || mb_strlen($trimmed, 'UTF-8') > 3000) {
            return ['success' => false, 'error' => 'Review body must be between 3 and 3000 characters.'];
        }

        $updated = MultimediaReview::saveReview(
            $userId,
            (string)$review->content_type,
            (int)$review->content_id,
            $trimmed,
            $title,
            $rating,
            $isSpoiler,
            (string)$review->status
        );

        return [
            'success' => $updated !== null,
            'review'  => $updated ? self::hydrateReview($updated, $user) : null,
        ];
    }

    public static function deleteReview(mixed $user, int $reviewId): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'];
        }

        if (!self::hasReviewsTable()) {
            return ['success' => false, 'error' => 'Reviews unavailable.'];
        }

        $review = MultimediaReview::find($reviewId);
        if (!$review) {
            return ['success' => false, 'error' => 'Review not found.'];
        }

        $isModerator = is_object($user) && (
            (method_exists($user, 'hasRole') && ($user->hasRole('admin') || $user->hasRole('super-admin')))
            || (isset($user->role) && in_array($user->role, ['admin', 'super-admin', 'administrator'], true))
            || MultimediaPermission::can(MultimediaPermission::MODERATE, $user)
        );
        if ((int)$review->user_id !== $userId && !$isModerator) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'You do not have permission to delete this review.'];
        }

        $ok = MultimediaReview::deleteReview($reviewId);
        return ['success' => $ok];
    }

    public static function voteHelpfulReview(mixed $user, int $reviewId): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Please log in to vote.'];
        }

        if (!self::hasReviewsTable()) {
            return ['success' => false, 'error' => 'Reviews unavailable.'];
        }

        return MultimediaReview::voteHelpful($userId, $reviewId);
    }

    public static function getReviewsForContent(
        string $contentType,
        int $contentId,
        int $limit = 10,
        int $offset = 0,
        string $sort = 'newest',
        mixed $user = null
    ): array {
        if (!self::hasReviewsTable()) {
            return ['reviews' => [], 'total' => 0];
        }

        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        $items = MultimediaReview::getForContent($contentType, $contentId, $limit, $offset, $sort, $userId > 0 ? $userId : null);
        $total = MultimediaReview::countForContent($contentType, $contentId);

        $hydrated = array_map(fn($r) => self::hydrateReview($r, $user), $items);

        return [
            'reviews' => $hydrated,
            'total'   => $total,
        ];
    }

    // -------------------------------------------------------------
    // Comments
    // -------------------------------------------------------------

    public static function createComment(
        mixed $user,
        string $contentType,
        int $contentId,
        string $body,
        ?int $parentId = null
    ): array {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required to comment.'];
        }

        if (!in_array($contentType, MultimediaComment::ALLOWED_TYPES, true)) {
            return ['success' => false, 'error' => 'Invalid content type for comments.'];
        }

        $trimmed = trim($body);
        if (mb_strlen($trimmed, 'UTF-8') < 1) {
            return ['success' => false, 'error' => 'Comment cannot be empty.'];
        }
        if (mb_strlen($trimmed, 'UTF-8') > 1000) {
            return ['success' => false, 'error' => 'Comment cannot exceed 1000 characters.'];
        }

        if (!self::hasCommentsTable()) {
            return ['success' => false, 'error' => 'Comments unavailable.'];
        }

        $comment = MultimediaComment::createComment($userId, $contentType, $contentId, $trimmed, $parentId);
        if (!$comment) {
            return ['success' => false, 'error' => 'Failed to create comment.'];
        }

        return [
            'success' => true,
            'comment' => self::hydrateComment($comment, $user),
        ];
    }

    public static function updateComment(mixed $user, int $commentId, string $body): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'];
        }

        if (!self::hasCommentsTable()) {
            return ['success' => false, 'error' => 'Comments unavailable.'];
        }

        $comment = MultimediaComment::find($commentId);
        if (!$comment) {
            return ['success' => false, 'error' => 'Comment not found.'];
        }

        if ((int)$comment->user_id !== $userId) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'You can only edit your own comment.'];
        }

        $trimmed = trim($body);
        if (mb_strlen($trimmed, 'UTF-8') < 1 || mb_strlen($trimmed, 'UTF-8') > 1000) {
            return ['success' => false, 'error' => 'Comment must be between 1 and 1000 characters.'];
        }

        $ok = MultimediaComment::updateComment($commentId, $userId, $trimmed);
        $updated = MultimediaComment::find($commentId);

        return [
            'success' => $ok,
            'comment' => $updated ? self::hydrateComment($updated, $user) : null,
        ];
    }

    public static function deleteComment(mixed $user, int $commentId): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required.'];
        }

        if (!self::hasCommentsTable()) {
            return ['success' => false, 'error' => 'Comments unavailable.'];
        }

        $comment = MultimediaComment::find($commentId);
        if (!$comment) {
            return ['success' => false, 'error' => 'Comment not found.'];
        }

        $isModerator = is_object($user) && (
            (method_exists($user, 'hasRole') && ($user->hasRole('admin') || $user->hasRole('super-admin')))
            || (isset($user->role) && in_array($user->role, ['admin', 'super-admin', 'administrator'], true))
            || MultimediaPermission::can(MultimediaPermission::MODERATE, $user)
        );
        if ((int)$comment->user_id !== $userId && !$isModerator) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'You do not have permission to delete this comment.'];
        }

        $ok = MultimediaComment::deleteComment($commentId);
        return ['success' => $ok];
    }

    public static function getCommentsForContent(string $contentType, int $contentId, int $limit = 20, int $offset = 0, mixed $user = null): array
    {
        if (!self::hasCommentsTable()) {
            return ['comments' => [], 'total' => 0];
        }

        $comments = MultimediaComment::getForContent($contentType, $contentId, $limit, $offset);
        $total = MultimediaComment::countForContent($contentType, $contentId);

        $hydrated = array_map(function ($c) use ($user) {
            $h = self::hydrateComment($c, $user);
            $h['replies'] = array_map(fn($r) => self::hydrateComment($r, $user), $c->replies ?? []);
            return $h;
        }, $comments);

        return [
            'comments' => $hydrated,
            'total'    => $total,
        ];
    }

    // -------------------------------------------------------------
    // Reporting & Community Safety
    // -------------------------------------------------------------

    public static function createReport(mixed $user, string $targetType, int $targetId, string $reason, ?string $notes = null): array
    {
        $userId = is_object($user) ? (int)($user->id ?? 0) : (int)$user;
        if ($userId <= 0) {
            return ['success' => false, 'status' => 'unauthenticated', 'error' => 'Authentication required to report content.'];
        }

        if (!self::hasReportsTable()) {
            return ['success' => false, 'error' => 'Reporting system unavailable.'];
        }

        return MultimediaReport::fileReport($userId, $targetType, $targetId, $reason, $notes);
    }

    // -------------------------------------------------------------
    // Moderation
    // -------------------------------------------------------------

    public static function moderateReview(mixed $moderator, int $reviewId, string $action): array
    {
        if (!self::canModerate($moderator)) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'Admin or moderator privileges required.'];
        }

        $review = MultimediaReview::find($reviewId);
        if (!$review) {
            return ['success' => false, 'error' => 'Review not found.'];
        }

        $db = self::getDb();
        if ($action === 'delete') {
            MultimediaReview::deleteReview($reviewId);
            return ['success' => true, 'action' => 'delete'];
        }

        $status = match ($action) {
            'approve', 'approved' => MultimediaReview::STATUS_APPROVED,
            'reject', 'rejected'  => MultimediaReview::STATUS_REJECTED,
            'hide', 'hidden'      => MultimediaReview::STATUS_HIDDEN,
            default               => null,
        };

        if (!$status) {
            return ['success' => false, 'error' => 'Invalid moderation action.'];
        }

        $oldStatus = (string)$review->status;
        $db->update('multimedia_reviews', [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $reviewId]);

        MultimediaNotificationService::onReviewModerated($review, $oldStatus, $status);

        return ['success' => true, 'action' => $action, 'new_status' => $status];
    }

    public static function moderateComment(mixed $moderator, int $commentId, string $action): array
    {
        if (!self::canModerate($moderator)) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'Admin or moderator privileges required.'];
        }

        $comment = MultimediaComment::find($commentId);
        if (!$comment) {
            return ['success' => false, 'error' => 'Comment not found.'];
        }

        $db = self::getDb();
        if ($action === 'delete') {
            MultimediaComment::deleteComment($commentId);
            return ['success' => true, 'action' => 'delete'];
        }

        $status = match ($action) {
            'approve', 'approved' => MultimediaComment::STATUS_APPROVED,
            'reject', 'rejected'  => MultimediaComment::STATUS_REJECTED,
            'hide', 'hidden'      => MultimediaComment::STATUS_HIDDEN,
            default               => null,
        };

        if (!$status) {
            return ['success' => false, 'error' => 'Invalid moderation action.'];
        }

        $oldStatus = (string)$comment->status;
        $db->update('multimedia_comments', [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $commentId]);

        MultimediaNotificationService::onCommentModerated($comment, $oldStatus, $status);

        return ['success' => true, 'action' => $action, 'new_status' => $status];
    }

    public static function resolveReport(mixed $moderator, int $reportId, string $action): array
    {
        if (!self::canModerate($moderator)) {
            return ['success' => false, 'status' => 'forbidden', 'error' => 'Admin or moderator privileges required.'];
        }

        $status = match ($action) {
            'dismiss' => MultimediaReport::STATUS_DISMISSED,
            'action'  => MultimediaReport::STATUS_ACTIONED,
            'review'  => MultimediaReport::STATUS_REVIEWED,
            default   => null,
        };

        if (!$status) {
            return ['success' => false, 'error' => 'Invalid resolution status.'];
        }

        $ok = MultimediaReport::resolveReport($reportId, $status);
        return ['success' => $ok, 'status' => $status];
    }

    public static function canModerate(mixed $user): bool
    {
        if (!is_object($user)) {
            return false;
        }
        if (isset($user->role) && in_array($user->role, ['admin', 'super-admin', 'administrator'], true)) {
            return true;
        }
        if (method_exists($user, 'hasRole') && ($user->hasRole('admin') || $user->hasRole('super-admin') || $user->hasRole('administrator'))) {
            return true;
        }
        return MultimediaPermission::can(MultimediaPermission::MODERATE, $user);
    }

    public static function getModerationCounts(): array
    {
        $db = self::getDb();
        $pendingReviews = 0;
        $reportedReviews = 0;
        $openReports = 0;

        if (self::hasReviewsTable()) {
            $r = $db->selectOne("SELECT COUNT(*) as c FROM multimedia_reviews WHERE status = 'pending'");
            $pendingReviews = (int)($r->c ?? 0);
        }

        if (self::hasReportsTable()) {
            $r2 = $db->selectOne("SELECT COUNT(*) as c FROM multimedia_reports WHERE status = 'open'");
            $openReports = (int)($r2->c ?? 0);
        }

        return [
            'pending_reviews' => $pendingReviews,
            'open_reports'    => $openReports,
            'total_pending'   => $pendingReviews + $openReports,
        ];
    }

    // -------------------------------------------------------------
    // Cascade Cleanup
    // -------------------------------------------------------------

    public static function deleteForContent(string $contentType, int $contentId): void
    {
        if ($contentId <= 0) {
            return;
        }

        if (self::hasRatingsTable()) {
            MultimediaRating::deleteForContent($contentType, $contentId);
        }
        if (self::hasReviewsTable()) {
            MultimediaReview::deleteForContent($contentType, $contentId);
        }
        if (self::hasCommentsTable()) {
            MultimediaComment::deleteForContent($contentType, $contentId);
        }
    }

    // -------------------------------------------------------------
    // Safe Hydration & Privacy
    // -------------------------------------------------------------

    public static function formatAuthorDisplay(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'display_name' => 'Community Member',
                'avatar'       => null,
                'is_deleted'   => false,
            ];
        }

        try {
            $u = User::find($userId);
            if (!$u) {
                return [
                    'display_name' => 'Deleted User',
                    'avatar'       => null,
                    'is_deleted'   => true,
                ];
            }

            $name = (string)($u->name ?: ($u->username ?: 'Community Member'));
            $avatar = !empty($u->avatar) ? (string)$u->avatar : null;

            return [
                'display_name' => $name,
                'avatar'       => $avatar,
                'is_deleted'   => false,
            ];
        } catch (\Throwable) {
            return [
                'display_name' => 'Community Member',
                'avatar'       => null,
                'is_deleted'   => false,
            ];
        }
    }

    public static function hydrateReview(MultimediaReview $review, mixed $currentUser = null): array
    {
        $currentUserId = is_object($currentUser) ? (int)($currentUser->id ?? 0) : (int)$currentUser;
        $author = self::formatAuthorDisplay((int)$review->user_id);
        $hasVotedHelpful = ($currentUserId > 0 && self::hasReviewsTable())
            ? MultimediaReview::hasVotedHelpful($currentUserId, (int)$review->id)
            : false;

        $isOwner = ($currentUserId > 0 && (int)$review->user_id === $currentUserId);
        $isEdited = !empty($review->updated_at) && ($review->updated_at !== $review->created_at);

        return [
            'id'                => (int)$review->id,
            'content_type'      => (string)$review->content_type,
            'content_id'        => (int)$review->content_id,
            'title'             => $review->title ? (string)$review->title : null,
            'body'              => (string)$review->body,
            'rating'            => $review->rating ? (int)$review->rating : null,
            'status'            => (string)$review->status,
            'contains_spoiler'  => (bool)$review->contains_spoiler,
            'helpful_count'     => (int)($review->helpful_count ?? 0),
            'has_voted_helpful' => $hasVotedHelpful,
            'is_owner'          => $isOwner,
            'is_edited'         => $isEdited,
            'created_at'        => (string)$review->created_at,
            'updated_at'        => (string)$review->updated_at,
            'author'            => $author,
        ];
    }

    public static function hydrateComment(MultimediaComment $comment, mixed $currentUser = null): array
    {
        $currentUserId = is_object($currentUser) ? (int)($currentUser->id ?? 0) : (int)$currentUser;
        $author = self::formatAuthorDisplay((int)$comment->user_id);
        $isOwner = ($currentUserId > 0 && (int)$comment->user_id === $currentUserId);
        $isEdited = !empty($comment->updated_at) && ($comment->updated_at !== $comment->created_at);

        return [
            'id'           => (int)$comment->id,
            'content_type' => (string)$comment->content_type,
            'content_id'   => (int)$comment->content_id,
            'parent_id'    => $comment->parent_id ? (int)$comment->parent_id : null,
            'body'         => (string)$comment->body,
            'status'       => (string)$comment->status,
            'is_owner'     => $isOwner,
            'is_edited'    => $isEdited,
            'created_at'   => (string)$comment->created_at,
            'updated_at'   => (string)$comment->updated_at,
            'author'       => $author,
        ];
    }
}
