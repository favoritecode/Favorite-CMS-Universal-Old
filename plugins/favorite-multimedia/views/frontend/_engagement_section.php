<?php
/**
 * Favorite Multimedia — Community Engagement Partial
 *
 * Renders star ratings, written reviews, comment discussion, and reporting modal.
 *
 * Expected variables:
 * - $contentType (string: movie, series, episode, song, playlist)
 * - $contentId (int)
 * - $user (User|null)
 * - $ratingAggregate (array: ['average' => float, 'count' => int])
 * - $userRating (int|null)
 * - $reviewsData (array: ['reviews' => array, 'total' => int])
 * - $commentsData (array: ['comments' => array, 'total' => int])
 */

use FavoriteCMS\Multimedia\Services\MultimediaEngagementService;

if (!isset($user)) {
    $user = function_exists('favorite_multimedia_theme_current_user')
        ? favorite_multimedia_theme_current_user()
        : (function_exists('current_user') ? current_user() : null);
}

if (!isset($ratingAggregate)) {
    if (class_exists(MultimediaEngagementService::class) && isset($contentType, $contentId)) {
        try {
            $ratingAggregate = MultimediaEngagementService::getRatingAggregate($contentType, (int)$contentId);
        } catch (\Throwable) {
            $ratingAggregate = ['average' => 0.0, 'count' => 0];
        }
    } else {
        $ratingAggregate = ['average' => 0.0, 'count' => 0];
    }
}

if (!isset($userRating)) {
    if (class_exists(MultimediaEngagementService::class) && !empty($user) && isset($contentType, $contentId)) {
        try {
            $userRating = MultimediaEngagementService::getUserRating($user, $contentType, (int)$contentId);
        } catch (\Throwable) {
            $userRating = null;
        }
    } else {
        $userRating = null;
    }
}

if (!isset($reviewsData)) {
    if (class_exists(MultimediaEngagementService::class) && isset($contentType, $contentId) && in_array($contentType, ['movie', 'series', 'song', 'playlist'], true)) {
        try {
            $reviewsData = MultimediaEngagementService::getReviewsForContent($contentType, (int)$contentId, 20, 0, 'newest', !empty($user) ? $user : null);
        } catch (\Throwable) {
            $reviewsData = ['reviews' => [], 'total' => 0];
        }
    } else {
        $reviewsData = ['reviews' => [], 'total' => 0];
    }
}

if (!isset($commentsData)) {
    if (class_exists(MultimediaEngagementService::class) && isset($contentType, $contentId)) {
        try {
            $commentsData = MultimediaEngagementService::getCommentsForContent($contentType, (int)$contentId, 20, 0, !empty($user) ? $user : null);
        } catch (\Throwable) {
            $commentsData = ['comments' => [], 'total' => 0];
        }
    } else {
        $commentsData = ['comments' => [], 'total' => 0];
    }
}

$csrfToken = function_exists('csrf_token') ? (string)csrf_token() : (string)($_SESSION['_token'] ?? $_SESSION['csrf_token'] ?? '');
if ($csrfToken === '' && session_status() === PHP_SESSION_ACTIVE) {
    try {
        $_SESSION['_token'] = bin2hex(random_bytes(32));
        $csrfToken = $_SESSION['_token'];
    } catch (\Throwable) {}
}

$ratingAvg = (float)($ratingAggregate['average'] ?? 0.0);
$ratingCnt = (int)($ratingAggregate['count'] ?? 0);
$userScore = $userRating ?? null;
$reviews = $reviewsData['reviews'] ?? [];
$reviewCount = (int)($reviewsData['total'] ?? 0);
$comments = $commentsData['comments'] ?? [];
$commentCount = (int)($commentsData['total'] ?? 0);
$isLoggedIn = !empty($user);
$allowsReviews = in_array($contentType, ['movie', 'series', 'song', 'playlist'], true);
?>

<div class="fav-engagement-container" data-content-type="<?php echo htmlspecialchars($contentType, ENT_QUOTES, 'UTF-8'); ?>" data-content-id="<?php echo (int)$contentId; ?>" data-csrf="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" data-is-logged-in="<?php echo $isLoggedIn ? '1' : '0'; ?>">
    
    <!-- 1. Community Rating Bar -->
    <div class="fav-rating-shelf">
        <div class="fav-rating-summary">
            <div class="fav-rating-big">
                <span class="fav-rating-number" id="fmm-avg-rating"><?php echo $ratingCnt > 0 ? number_format($ratingAvg, 1) : '—'; ?></span>
                <span class="fav-rating-star">★</span>
            </div>
            <div class="fav-rating-meta">
                <div class="fav-rating-title" style="font-weight:700; font-size:16px; color:var(--fm-color-text-primary, #f8fafc);">Community Score</div>
                <div class="fav-rating-count-label" style="color:var(--fm-color-text-muted, #94a3b8); font-size:13px;" id="fmm-rating-count">
                    <?php echo $ratingCnt === 0 ? 'No ratings yet' : ($ratingCnt === 1 ? '1 rating' : "{$ratingCnt} ratings"); ?>
                </div>
            </div>
        </div>

        <div class="fav-user-rating-box">
            <div class="fav-user-rating-label" style="font-size:13px; color:var(--fm-color-text-secondary, #cbd5e1); margin-bottom:6px; font-weight:600;">
                <?php echo $isLoggedIn ? 'Your Rating:' : 'Sign in to rate:'; ?>
            </div>
            <div class="fav-star-picker" role="radiogroup" aria-label="Rating out of 5 stars">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                    <button type="button"
                            class="fav-star-btn <?php echo ($userScore !== null && $s <= $userScore) ? 'active' : ''; ?>"
                            data-star="<?php echo $s; ?>"
                            aria-label="<?php echo $s; ?> star<?php echo $s > 1 ? 's' : ''; ?>">
                        ★
                    </button>
                <?php endfor; ?>
                <?php if ($isLoggedIn && $userScore !== null): ?>
                    <button type="button" class="fav-clear-rate-btn" title="Remove rating" id="fmm-clear-rating">&times;</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 2. Reviews Section -->
    <?php if ($allowsReviews): ?>
    <div class="fav-reviews-section">
        <div class="fav-section-header">
            <h3 class="fav-section-title">💬 Audience Reviews <span class="fav-count-badge"><?php echo $reviewCount; ?></span></h3>
            <?php if ($isLoggedIn): ?>
                <button type="button" class="fav-btn-action" id="fmm-toggle-review-form">✍️ Write a Review</button>
            <?php endif; ?>
        </div>

        <!-- Write / Edit Review Form -->
        <?php if ($isLoggedIn): ?>
            <div class="fav-review-form-card" id="fmm-review-form" style="display:none;">
                <h4 style="margin:0 0 12px 0; font-size:15px; color:#f8fafc;">Write Your Review</h4>
                <form id="fmm-submit-review" action="/multimedia/api/review" method="POST" data-endpoint="/multimedia/api/review">
                    <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="content_type" value="<?php echo htmlspecialchars($contentType, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="content_id" value="<?php echo (int)$contentId; ?>">
                    <input type="hidden" name="rating" id="fmm-review-rating-input" value="<?php echo $userScore !== null ? (int)$userScore : ''; ?>">
                    
                    <div class="fav-review-form-rating" style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                        <span style="font-size:13px; color:var(--fm-color-text-secondary, #cbd5e1); font-weight:600;">Your Rating:</span>
                        <div class="fav-star-picker fmm-review-star-picker" role="radiogroup" aria-label="Review rating">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <button type="button"
                                        class="fav-star-btn <?php echo ($userScore !== null && $s <= $userScore) ? 'active' : ''; ?>"
                                        data-star="<?php echo $s; ?>"
                                        aria-label="<?php echo $s; ?> star<?php echo $s > 1 ? 's' : ''; ?>">
                                    ★
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <input type="text" name="title" placeholder="Review headline (optional)" class="fav-form-input" maxlength="255">
                    <textarea name="body" placeholder="Share your thoughts about this title (min. 3 characters)..." class="fav-form-textarea" rows="4" required minlength="3" maxlength="3000"></textarea>
                    
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-top:12px;">
                        <label style="font-size:13px; color:#cbd5e1; display:flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" name="contains_spoiler" value="1">
                            ⚠️ This review contains spoilers
                        </label>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="fav-btn-secondary" id="fmm-cancel-review">Cancel</button>
                            <button type="submit" class="fav-btn-primary">Post Review</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Reviews List -->
        <div class="fav-reviews-list" id="fmm-reviews-container">
            <?php if (empty($reviews)): ?>
                <div class="fav-empty-block">
                    <p style="margin:0; color:#94a3b8; font-size:14px;">No reviews yet. Be the first to share your thoughts!</p>
                </div>
            <?php else: ?>
                <?php foreach ($reviews as $rev): ?>
                    <div class="fav-review-card" data-review-id="<?php echo (int)$rev['id']; ?>">
                        <div class="fav-review-header">
                            <div class="fav-author-meta">
                                <div class="fav-avatar-circle"><?php echo strtoupper(substr($rev['author']['display_name'] ?? 'U', 0, 1)); ?></div>
                                <div>
                                    <div class="fav-author-name"><?php echo htmlspecialchars($rev['author']['display_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="fav-item-date">
                                        <?php echo htmlspecialchars(substr($rev['created_at'], 0, 10), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($rev['is_edited'])): ?> • <span style="font-style:italic;">Edited</span><?php endif; ?>
                                        <?php if (($rev['status'] ?? '') === 'pending'): ?>
                                            • <span style="color:#f59e0b; font-weight:600;">(Pending Moderation)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <?php if (!empty($rev['rating'])): ?>
                                    <div class="fav-score-pill">★ <?php echo (int)$rev['rating']; ?>/5</div>
                                <?php endif; ?>
                                <?php if (!empty($rev['is_owner'])): ?>
                                    <button type="button" class="fav-icon-btn fmm-delete-review" title="Delete review" data-id="<?php echo (int)$rev['id']; ?>">🗑️</button>
                                <?php elseif ($isLoggedIn): ?>
                                    <button type="button" class="fav-icon-btn fmm-report-btn" title="Report review" data-target-type="review" data-target-id="<?php echo (int)$rev['id']; ?>">🚩</button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($rev['title'])): ?>
                            <h4 class="fav-review-title"><?php echo htmlspecialchars($rev['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                        <?php endif; ?>

                        <?php if (!empty($rev['contains_spoiler'])): ?>
                            <div class="fav-spoiler-banner">
                                <span>⚠️ This review contains spoilers.</span>
                                <button type="button" class="fav-reveal-spoiler-btn">Show Review</button>
                            </div>
                            <div class="fav-review-body fav-spoiler-content" style="display:none;">
                                <?php echo nl2br(htmlspecialchars($rev['body'], ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                        <?php else: ?>
                            <div class="fav-review-body">
                                <?php echo nl2br(htmlspecialchars($rev['body'], ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                        <?php endif; ?>

                        <div class="fav-review-footer">
                            <button type="button" class="fav-helpful-btn <?php echo !empty($rev['has_voted_helpful']) ? 'active' : ''; ?>" data-id="<?php echo (int)$rev['id']; ?>" <?php if (!$isLoggedIn): ?>onclick="window.location.href='/admin/login?redirect='+encodeURIComponent(window.location.pathname + window.location.search);"<?php endif; ?>>
                                👏 Helpful (<span class="fmm-helpful-count"><?php echo (int)($rev['helpful_count'] ?? 0); ?></span>)
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 3. Discussion & Comments -->
    <div class="fav-comments-section">
        <div class="fav-section-header">
            <h3 class="fav-section-title">🗣️ Discussion <span class="fav-count-badge"><?php echo $commentCount; ?></span></h3>
        </div>

        <form id="fmm-post-comment-form" class="fav-comment-composer" action="/multimedia/api/comment" method="POST" data-endpoint="/multimedia/api/comment">
            <input type="hidden" name="_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="content_type" value="<?php echo htmlspecialchars($contentType, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="content_id" value="<?php echo (int)$contentId; ?>">
            <textarea name="body" placeholder="<?php echo $isLoggedIn ? 'Join the discussion...' : 'Sign in to join the discussion...'; ?>" class="fav-form-textarea" rows="2" <?php echo $isLoggedIn ? 'required' : ''; ?> minlength="1" maxlength="1000"></textarea>
            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; flex-wrap:wrap; gap:8px;">
                <?php if (!$isLoggedIn): ?>
                    <span class="fav-login-hint" style="margin:0; font-size:13px; color:#94a3b8;">
                        <a href="/admin/login?redirect=<?php echo urlencode($_SERVER['REQUEST_URI'] ?? '/multimedia'); ?>">Sign in</a> to participate in the discussion.
                    </span>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <button type="submit" class="fav-btn-primary">Post Comment</button>
            </div>
        </form>

        <div class="fav-comments-list" id="fmm-comments-container">
            <?php if (empty($comments)): ?>
                <div class="fav-empty-block">
                    <p style="margin:0; color:#94a3b8; font-size:14px;">No comments yet. Start the conversation!</p>
                </div>
            <?php else: ?>
                <?php foreach ($comments as $comm): ?>
                    <div class="fav-comment-thread" data-comment-id="<?php echo (int)$comm['id']; ?>">
                        <div class="fav-comment-card">
                            <div class="fav-comment-header">
                                <span class="fav-author-name"><?php echo htmlspecialchars($comm['author']['display_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="fav-item-date"><?php echo htmlspecialchars(substr($comm['created_at'], 0, 16), ENT_QUOTES, 'UTF-8'); ?></span>
                                <div style="margin-left:auto; display:flex; gap:6px;">
                                    <?php if (!empty($comm['is_owner'])): ?>
                                        <button type="button" class="fav-icon-btn fmm-delete-comment" data-id="<?php echo (int)$comm['id']; ?>" title="Delete">🗑️</button>
                                    <?php elseif ($isLoggedIn): ?>
                                        <button type="button" class="fav-icon-btn fmm-report-btn" data-target-type="comment" data-target-id="<?php echo (int)$comm['id']; ?>" title="Report">🚩</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="fav-comment-body">
                                <?php echo nl2br(htmlspecialchars($comm['body'], ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                            <?php if ($isLoggedIn): ?>
                                <button type="button" class="fav-reply-btn fmm-reply-trigger" data-parent-id="<?php echo (int)$comm['id']; ?>">Reply</button>
                            <?php endif; ?>
                        </div>

                        <!-- Nested Replies (1-level depth) -->
                        <?php if (!empty($comm['replies'])): ?>
                            <div class="fav-replies-wrap">
                                <?php foreach ($comm['replies'] as $reply): ?>
                                    <div class="fav-comment-card fav-reply-card" data-comment-id="<?php echo (int)$reply['id']; ?>">
                                        <div class="fav-comment-header">
                                            <span class="fav-author-name"><?php echo htmlspecialchars($reply['author']['display_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="fav-item-date"><?php echo htmlspecialchars(substr($reply['created_at'], 0, 16), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <div style="margin-left:auto; display:flex; gap:6px;">
                                                <?php if (!empty($reply['is_owner'])): ?>
                                                    <button type="button" class="fav-icon-btn fmm-delete-comment" data-id="<?php echo (int)$reply['id']; ?>" title="Delete">🗑️</button>
                                                <?php elseif ($isLoggedIn): ?>
                                                    <button type="button" class="fav-icon-btn fmm-report-btn" data-target-type="comment" data-target-id="<?php echo (int)$reply['id']; ?>" title="Report">🚩</button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="fav-comment-body">
                                            <?php echo nl2br(htmlspecialchars($reply['body'], ENT_QUOTES, 'UTF-8')); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4. Community Safety Reporting Modal -->
    <div id="fmm-report-modal" class="fav-modal-backdrop" style="display:none;">
        <div class="fav-modal-dialog">
            <h3 style="margin:0 0 12px 0; font-size:18px; color:#0f172a;">🚩 Report Content</h3>
            <p style="font-size:13px; color:#64748b; margin:0 0 16px 0;">Help keep our community safe. Please select a reason for reporting this contribution.</p>
            <form id="fmm-report-form">
                <input type="hidden" name="target_type" id="fmm-report-target-type" value="">
                <input type="hidden" name="target_id" id="fmm-report-target-id" value="">
                
                <div style="margin-bottom:12px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:6px;">Reason:</label>
                    <select name="reason" class="fav-form-input" required>
                        <option value="spam">Spam or unwanted advertising</option>
                        <option value="harassment">Harassment, hate speech, or abuse</option>
                        <option value="off_topic">Off-topic or irrelevant</option>
                        <option value="other">Other policy violation</option>
                    </select>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:6px;">Additional context (optional):</label>
                    <textarea name="notes" rows="3" class="fav-form-textarea" maxlength="255" placeholder="Briefly describe the issue..."></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" class="fav-btn-secondary" id="fmm-close-report-modal">Cancel</button>
                    <button type="submit" class="fav-btn-primary" style="background:#dc2626; border-color:#dc2626;">Submit Report</button>
                </div>
            </form>
        </div>
    </div>

</div>
<script src="/plugins/favorite-multimedia/assets/js/multimedia-engagement.js?v=1.0.7"></script>
