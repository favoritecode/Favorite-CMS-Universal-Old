/**
 * Favorite Multimedia — Community Engagement Client
 *
 * Manages star rating, reviews, discussion comments, community reporting,
 * subscriptions, and notifications.
 */
(function () {
    if (window.__FM_ENGAGEMENT_INITIALIZED__) return;
    window.__FM_ENGAGEMENT_INITIALIZED__ = true;

    function showToast(message, type) {
        type = type || 'info';
        if (window.FavoriteToast) {
            if (typeof window.FavoriteToast[type] === 'function') {
                window.FavoriteToast[type](message);
                return;
            }
            if (typeof window.FavoriteToast.show === 'function') {
                window.FavoriteToast.show(message, type);
                return;
            }
        }
        let container = document.getElementById('fm-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'fm-toast-container';
            container.className = 'fm-toast-container';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = 'fm-toast fm-toast-' + type;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.remove();
        }, 3500);
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function getCsrf(container) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) return meta.content;
        const input = document.querySelector('input[name="_token"]');
        if (input && input.value) return input.value;
        if (container && container.dataset.csrf) return container.dataset.csrf;
        return '';
    }

    function getLoginRedirectUrl() {
        const fullPath = window.location.pathname + (window.location.search || '');
        return '/admin/login?redirect=' + encodeURIComponent(fullPath);
    }

    function redirectToLogin() {
        window.location.href = getLoginRedirectUrl();
    }

    function sendEngagementRequest(url, data, token) {
        const params = new URLSearchParams();
        if (data) {
            for (const key in data) {
                if (Object.prototype.hasOwnProperty.call(data, key) && data[key] !== null && data[key] !== undefined) {
                    if (typeof data[key] === 'object') {
                        params.append(key, JSON.stringify(data[key]));
                    } else {
                        params.append(key, data[key]);
                    }
                }
            }
        }
        if (token && !params.has('_token')) {
            params.append('_token', token);
        }

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN': token || '',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: params.toString()
        });
    }

    function initEngagement() {
        const container = document.querySelector('.fav-engagement-container');
        if (!container) return;

        const contentType = container.dataset.contentType;
        const contentId = parseInt(container.dataset.contentId, 10);
        const isLoggedIn = container.dataset.isLoggedIn === '1';

        // 1. Star Rating
        let isRatingInFlight = false;
        const starBtns = container.querySelectorAll('.fav-star-btn');
        starBtns.forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                if (!isLoggedIn) {
                    showToast('Please sign in to rate.', 'warning');
                    setTimeout(() => {
                        redirectToLogin();
                    }, 500);
                    return;
                }

                if (isRatingInFlight) return;
                const star = parseInt(this.dataset.star, 10);
                if (!star || isNaN(star)) return;

                isRatingInFlight = true;
                const token = getCsrf(container);

                sendEngagementRequest('/multimedia/api/rate', {
                    _token: token,
                    content_type: contentType,
                    content_id: contentId,
                    rating: star
                }, token)
                .then(res => {
                    if (!res.ok && res.status === 401) {
                        return res.json().then(data => Promise.reject({ isAuth: true, message: data.error || 'Please sign in to rate.' }));
                    }
                    return res.json();
                })
                .then(data => {
                    if (data.success) {
                        starBtns.forEach(b => {
                            const s = parseInt(b.dataset.star, 10);
                            if (s <= data.user_rating) {
                                b.classList.add('active');
                            } else {
                                b.classList.remove('active');
                            }
                        });

                        const avgEl = document.getElementById('fmm-avg-rating');
                        const cntEl = document.getElementById('fmm-rating-count');
                        if (avgEl) avgEl.textContent = data.average_rating ? Number(data.average_rating).toFixed(1) : '—';
                        if (cntEl) cntEl.textContent = (data.rating_count === 0) ? 'No ratings yet' : (data.rating_count === 1 ? '1 rating' : data.rating_count + ' ratings');

                        let clearBtn = document.getElementById('fmm-clear-rating');
                        if (!clearBtn) {
                            clearBtn = document.createElement('button');
                            clearBtn.id = 'fmm-clear-rating';
                            clearBtn.type = 'button';
                            clearBtn.className = 'fav-clear-rate-btn';
                            clearBtn.title = 'Remove rating';
                            clearBtn.innerHTML = '&times;';
                            btn.parentElement.appendChild(clearBtn);
                            attachClearHandler(clearBtn);
                        }
                        showToast('Rating saved.', 'success');
                    } else if (data.status === 'unauthenticated') {
                        showToast('Please sign in to rate.', 'warning');
                        setTimeout(() => {
                            redirectToLogin();
                        }, 500);
                    } else {
                        showToast(data.error || 'Unable to save rating.', 'error');
                    }
                })
                .catch(err => {
                    if (err && err.isAuth) {
                        showToast(err.message, 'warning');
                        setTimeout(() => {
                            redirectToLogin();
                        }, 500);
                    } else {
                        showToast('Unable to save rating.', 'error');
                    }
                })
                .finally(() => {
                    isRatingInFlight = false;
                });
            });
        });

        function attachClearHandler(btn) {
            btn.addEventListener('click', function () {
                const token = getCsrf(container);
                sendEngagementRequest('/multimedia/api/rate/remove', {
                    _token: token,
                    content_type: contentType,
                    content_id: contentId
                }, token)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        starBtns.forEach(b => b.classList.remove('active'));
                        const avgEl = document.getElementById('fmm-avg-rating');
                        const cntEl = document.getElementById('fmm-rating-count');
                        if (avgEl) avgEl.textContent = data.average_rating ? Number(data.average_rating).toFixed(1) : '—';
                        if (cntEl) cntEl.textContent = (data.rating_count === 0) ? 'No ratings yet' : (data.rating_count === 1 ? '1 rating' : data.rating_count + ' ratings');
                        btn.remove();
                        showToast('Rating removed.', 'info');
                    }
                })
                .catch(() => {
                    showToast('Failed to remove rating.', 'error');
                });
            });
        }

        const initialClearBtn = document.getElementById('fmm-clear-rating');
        if (initialClearBtn) attachClearHandler(initialClearBtn);

        // 2. Reviews Toggle Form & Submission
        const toggleReviewBtn = document.getElementById('fmm-toggle-review-form');
        const reviewFormCard = document.getElementById('fmm-review-form');
        const cancelReviewBtn = document.getElementById('fmm-cancel-review');

        if (toggleReviewBtn && reviewFormCard) {
            toggleReviewBtn.addEventListener('click', () => {
                reviewFormCard.style.display = (reviewFormCard.style.display === 'none') ? 'block' : 'none';
            });
        }

        if (cancelReviewBtn && reviewFormCard) {
            cancelReviewBtn.addEventListener('click', () => {
                reviewFormCard.style.display = 'none';
            });
        }

        const reviewForm = document.getElementById('fmm-submit-review');
        if (reviewForm) {
            let isReviewInFlight = false;
            const reviewRatingInput = document.getElementById('fmm-review-rating-input') || reviewForm.querySelector('input[name="rating"]');
            const reviewStarBtns = reviewForm.querySelectorAll('.fav-star-btn');

            reviewStarBtns.forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const star = parseInt(this.dataset.star, 10);
                    if (reviewRatingInput) reviewRatingInput.value = star;
                    reviewStarBtns.forEach(b => {
                        const s = parseInt(b.dataset.star, 10);
                        if (s <= star) b.classList.add('active');
                        else b.classList.remove('active');
                    });
                    // Visually sync main rating bar stars as well
                    starBtns.forEach(b => {
                        const s = parseInt(b.dataset.star, 10);
                        if (s <= star) b.classList.add('active');
                        else b.classList.remove('active');
                    });
                });
            });

            reviewForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!isLoggedIn) {
                    showToast('Please sign in to submit a review.', 'warning');
                    setTimeout(() => {
                        redirectToLogin();
                    }, 500);
                    return;
                }

                const titleInput = reviewForm.querySelector('input[name="title"]');
                const bodyInput = reviewForm.querySelector('textarea[name="body"]');
                const spoilerInput = reviewForm.querySelector('input[name="contains_spoiler"]');
                const submitBtn = reviewForm.querySelector('button[type="submit"]');

                const title = titleInput ? titleInput.value.trim() : '';
                const body = bodyInput ? bodyInput.value.trim() : '';
                const isSpoiler = spoilerInput ? spoilerInput.checked : false;
                const ratingVal = (reviewRatingInput && reviewRatingInput.value) ? parseInt(reviewRatingInput.value, 10) : null;

                if (body.length < 3) {
                    showToast('Review body must be at least 3 characters.', 'warning');
                    if (bodyInput) bodyInput.focus();
                    return;
                }

                if (isReviewInFlight) return;
                isReviewInFlight = true;
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Posting...';
                }

                const formTokenInput = reviewForm.querySelector('input[name="_token"]');
                const token = (formTokenInput && formTokenInput.value) ? formTokenInput.value : getCsrf(container);
                const formTypeInput = reviewForm.querySelector('input[name="content_type"]');
                const formIdInput = reviewForm.querySelector('input[name="content_id"]');
                const submitType = (formTypeInput && formTypeInput.value) ? formTypeInput.value : contentType;
                const submitId = (formIdInput && formIdInput.value) ? parseInt(formIdInput.value, 10) : contentId;

                sendEngagementRequest('/multimedia/api/review', {
                    _token: token,
                    content_type: submitType,
                    content_id: submitId,
                    title: title,
                    body: body,
                    rating: ratingVal,
                    contains_spoiler: isSpoiler ? 1 : 0
                }, token)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message || 'Review submitted successfully!', 'success');
                        setTimeout(() => window.location.reload(), 600);
                    } else {
                        showToast(data.error || 'Failed to submit review.', 'error');
                    }
                })
                .catch(() => {
                    showToast('Network error submitting review.', 'error');
                })
                .finally(() => {
                    isReviewInFlight = false;
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Post Review';
                    }
                });
            });
        }

        // 3. Spoilers Reveal
        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('fav-reveal-spoiler-btn')) {
                const card = e.target.closest('.fav-review-card');
                if (card) {
                    const banner = card.querySelector('.fav-spoiler-banner');
                    const content = card.querySelector('.fav-spoiler-content');
                    if (banner) banner.style.display = 'none';
                    if (content) content.style.display = 'block';
                }
            }
        });

        // 4. Helpful Vote
        container.addEventListener('click', function (e) {
            const btn = e.target.closest('.fav-helpful-btn');
            if (!btn) return;
            if (!isLoggedIn) {
                showToast('Please sign in to vote.', 'warning');
                setTimeout(() => {
                    redirectToLogin();
                }, 500);
                return;
            }

            const reviewId = btn.dataset.id;
            if (!reviewId) return;
            const token = getCsrf(container);
            sendEngagementRequest('/multimedia/api/review/' + reviewId + '/helpful', { _token: token }, token)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.voted) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                    const countSpan = btn.querySelector('.fmm-helpful-count');
                    if (countSpan) countSpan.textContent = data.helpful_count;
                } else if (data.status === 'unauthenticated') {
                    redirectToLogin();
                }
            })
            .catch(() => {
                showToast('Unable to record vote.', 'error');
            });
        });

        // 5. Delete Review
        container.addEventListener('click', function (e) {
            const btn = e.target.closest('.fmm-delete-review');
            if (!btn) return;
            const id = btn.dataset.id;
            if (!id || !confirm('Are you sure you want to delete your review?')) return;

            const token = getCsrf(container);
            sendEngagementRequest('/multimedia/api/review/' + id + '/delete', { _token: token }, token)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const card = btn.closest('.fav-review-card');
                    if (card) card.remove();
                    showToast('Review deleted.', 'info');
                } else {
                    showToast(data.error || 'Failed to delete review.', 'error');
                }
            })
            .catch(() => {
                showToast('Failed to delete review.', 'error');
            });
        });

        // 6. Discussion Comments
        const commentForm = document.getElementById('fmm-post-comment-form');
        if (commentForm) {
            let isCommentInFlight = false;
            commentForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!isLoggedIn) {
                    showToast('Please sign in to join the discussion.', 'warning');
                    setTimeout(() => {
                        redirectToLogin();
                    }, 500);
                    return;
                }

                const bodyInput = commentForm.querySelector('textarea[name="body"]');
                const submitBtn = commentForm.querySelector('button[type="submit"]');
                const body = bodyInput ? bodyInput.value.trim() : '';

                if (!body) {
                    showToast('Comment cannot be empty.', 'warning');
                    if (bodyInput) bodyInput.focus();
                    return;
                }

                if (body.length > 1000) {
                    showToast('Comment cannot exceed 1000 characters.', 'warning');
                    return;
                }

                if (isCommentInFlight) return;
                isCommentInFlight = true;
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Posting...';
                }

                const formTokenInput = commentForm.querySelector('input[name="_token"]');
                const token = (formTokenInput && formTokenInput.value) ? formTokenInput.value : getCsrf(container);
                const formTypeInput = commentForm.querySelector('input[name="content_type"]');
                const formIdInput = commentForm.querySelector('input[name="content_id"]');
                const submitType = (formTypeInput && formTypeInput.value) ? formTypeInput.value : contentType;
                const submitId = (formIdInput && formIdInput.value) ? parseInt(formIdInput.value, 10) : contentId;

                sendEngagementRequest('/multimedia/api/comment', {
                    _token: token,
                    content_type: submitType,
                    content_id: submitId,
                    body: body
                }, token)
                .then(res => {
                    if (!res.ok && res.status === 401) {
                        return res.json().then(data => Promise.reject({ isAuth: true, message: data.error || 'Please sign in to join the discussion.' }));
                    }
                    return res.json();
                })
                .then(data => {
                    if (data.success) {
                        showToast('Comment posted.', 'success');
                        if (bodyInput) bodyInput.value = '';

                        const commentsList = document.getElementById('fmm-comments-container');
                        if (commentsList && data.comment) {
                            const emptyBlock = commentsList.querySelector('.fav-empty-block');
                            if (emptyBlock) emptyBlock.remove();

                            const thread = document.createElement('div');
                            thread.className = 'fav-comment-thread';
                            thread.dataset.commentId = data.comment.id;
                            const authorName = data.comment.author ? (data.comment.author.display_name || 'You') : 'You';
                            thread.innerHTML = `
                                <div class="fav-comment-card">
                                    <div class="fav-comment-header">
                                        <span class="fav-author-name">${escapeHtml(authorName)}</span>
                                        <span class="fav-item-date">Just now</span>
                                        <div style="margin-left:auto; display:flex; gap:6px;">
                                            <button type="button" class="fav-icon-btn fmm-delete-comment" data-id="${data.comment.id}" title="Delete">🗑️</button>
                                        </div>
                                    </div>
                                    <div class="fav-comment-body">${escapeHtml(data.comment.body).replace(/\n/g, '<br>')}</div>
                                </div>
                            `;
                            commentsList.insertBefore(thread, commentsList.firstChild);

                            const badge = document.querySelector('.fav-comments-section .fav-count-badge');
                            if (badge) {
                                const current = parseInt(badge.textContent, 10) || 0;
                                badge.textContent = current + 1;
                            }
                        } else {
                            setTimeout(() => window.location.reload(), 500);
                        }
                    } else if (data.status === 'unauthenticated') {
                        showToast('Please sign in to join the discussion.', 'warning');
                        setTimeout(() => {
                            redirectToLogin();
                        }, 500);
                    } else {
                        showToast(data.error || 'Failed to post comment.', 'error');
                    }
                })
                .catch(err => {
                    if (err && err.isAuth) {
                        showToast(err.message, 'warning');
                        setTimeout(() => {
                            redirectToLogin();
                        }, 500);
                    } else {
                        showToast('Failed to post comment.', 'error');
                    }
                })
                .finally(() => {
                    isCommentInFlight = false;
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Post Comment';
                    }
                });
            });
        }

        // 7. Comment Reply
        container.addEventListener('click', function (e) {
            const btn = e.target.closest('.fmm-reply-trigger');
            if (!btn) return;
            if (!isLoggedIn) {
                showToast('Please sign in to reply.', 'warning');
                setTimeout(() => {
                    redirectToLogin();
                }, 500);
                return;
            }

            const parentId = btn.dataset.parentId;
            const thread = btn.closest('.fav-comment-thread');
            if (!thread || !parentId) return;

            let existingForm = thread.querySelector('.fav-reply-composer');
            if (existingForm) {
                existingForm.remove();
                return;
            }

            const composer = document.createElement('form');
            composer.className = 'fav-reply-composer';
            composer.innerHTML = `
                <textarea name="reply_body" placeholder="Write a reply..." class="fav-form-textarea" rows="2" required maxlength="1000"></textarea>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:6px;">
                    <button type="button" class="fav-btn-secondary fmm-cancel-reply">Cancel</button>
                    <button type="submit" class="fav-btn-primary">Post Reply</button>
                </div>
            `;
            thread.appendChild(composer);

            composer.querySelector('.fmm-cancel-reply').addEventListener('click', () => composer.remove());
            composer.addEventListener('submit', function (ev) {
                ev.preventDefault();
                const replyText = composer.querySelector('textarea').value.trim();
                if (!replyText) {
                    showToast('Reply cannot be empty.', 'warning');
                    return;
                }

                const token = getCsrf(container);
                sendEngagementRequest('/multimedia/api/comment', {
                    _token: token,
                    content_type: contentType,
                    content_id: contentId,
                    parent_id: parseInt(parentId, 10),
                    body: replyText
                }, token)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showToast('Reply posted.', 'success');
                        setTimeout(() => window.location.reload(), 500);
                    } else {
                        showToast(data.error || 'Failed to post reply.', 'error');
                    }
                })
                .catch(() => {
                    showToast('Failed to post reply.', 'error');
                });
            });
        });

        // 8. Delete Comment
        container.addEventListener('click', function (e) {
            const btn = e.target.closest('.fmm-delete-comment');
            if (!btn) return;
            const id = btn.dataset.id;
            if (!id || !confirm('Are you sure you want to delete this comment?')) return;

            const token = getCsrf(container);
            sendEngagementRequest('/multimedia/api/comment/' + id + '/delete', { _token: token }, token)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const card = btn.closest('.fav-comment-card') || btn.closest('.fav-comment-thread');
                    if (card) card.remove();
                    showToast('Comment deleted.', 'info');
                } else {
                    showToast(data.error || 'Failed to delete comment.', 'error');
                }
            })
            .catch(() => {
                showToast('Failed to delete comment.', 'error');
            });
        });

        // 9. Reporting Modal
        const reportModal = document.getElementById('fmm-report-modal');
        const reportForm = document.getElementById('fmm-report-form');
        const closeReportBtn = document.getElementById('fmm-close-report-modal');

        container.addEventListener('click', function (e) {
            const btn = e.target.closest('.fmm-report-btn');
            if (!btn || !reportModal) return;
            if (!isLoggedIn) {
                showToast('Please sign in to report content.', 'warning');
                setTimeout(() => {
                    redirectToLogin();
                }, 500);
                return;
            }

            const targetType = btn.dataset.targetType;
            const targetId = btn.dataset.targetId;

            document.getElementById('fmm-report-target-type').value = targetType;
            document.getElementById('fmm-report-target-id').value = targetId;
            reportModal.style.display = 'flex';
        });

        if (closeReportBtn && reportModal) {
            closeReportBtn.addEventListener('click', () => {
                reportModal.style.display = 'none';
            });
        }

        if (reportForm && reportModal) {
            reportForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const targetType = document.getElementById('fmm-report-target-type').value;
                const targetId = parseInt(document.getElementById('fmm-report-target-id').value, 10);
                const reason = reportForm.querySelector('select[name="reason"]').value;
                const notes = reportForm.querySelector('textarea[name="notes"]').value.trim();

                const token = getCsrf(container);
                sendEngagementRequest('/multimedia/api/report', {
                    _token: token,
                    target_type: targetType,
                    target_id: targetId,
                    reason: reason,
                    notes: notes
                }, token)
                .then(res => res.json())
                .then(data => {
                    reportModal.style.display = 'none';
                    if (data.success) {
                        showToast('Thank you for reporting this. Our moderation team will inspect it.', 'success');
                    } else if (data.duplicate) {
                        showToast('You have already reported this item.', 'info');
                    } else {
                        showToast(data.error || 'Failed to submit report.', 'error');
                    }
                })
                .catch(() => {
                    showToast('Failed to submit report.', 'error');
                });
            });
        }
    }

    function initSubscriptionsAndNotifications() {
        // 1. Follow / Subscribe Toggle Buttons
        document.querySelectorAll('[data-fmm-subscribe-toggle]').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const targetType = this.dataset.targetType;
                const targetId = parseInt(this.dataset.targetId, 10);
                if (!targetType || !targetId) return;

                const iconEl = this.querySelector('.fmm-follow-icon');
                const labelEl = this.querySelector('.fmm-follow-label');
                const token = getCsrf();

                sendEngagementRequest('/multimedia/api/subscribe/toggle', {
                    _token: token,
                    target_type: targetType,
                    target_id: targetId
                }, token)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (data.is_following) {
                            btn.classList.add('active');
                            if (iconEl) iconEl.textContent = '✓';
                            if (labelEl) labelEl.textContent = 'Following';
                            showToast('Subscribed.', 'success');
                        } else {
                            btn.classList.remove('active');
                            if (iconEl) iconEl.textContent = '+';
                            const typeLabel = targetType.charAt(0).toUpperCase() + targetType.slice(1);
                            if (labelEl) labelEl.textContent = 'Follow ' + typeLabel;
                            showToast('Unsubscribed.', 'info');
                        }
                    } else if (data.status === 'unauthenticated') {
                        showToast('Please sign in to follow.', 'warning');
                        setTimeout(() => {
                            redirectToLogin();
                        }, 500);
                    } else {
                        showToast(data.error || 'Failed to update follow status.', 'error');
                    }
                })
                .catch(() => {
                    showToast('Network error. Please try again.', 'error');
                });
            });
        });

        // 2. Mark Single Notification Read
        document.querySelectorAll('[data-fmm-read-action]').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const notifId = parseInt(this.dataset.fmmReadAction, 10);
                if (!notifId) return;

                const itemEl = this.closest('.fmm-notification-item');
                const token = getCsrf();
                sendEngagementRequest('/multimedia/api/notifications/' + notifId + '/read', { _token: token }, token)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (itemEl) {
                            itemEl.classList.remove('unread');
                            itemEl.classList.add('read');
                        }
                        btn.remove();
                        updateBadge(data.unread_badge);
                    }
                });
            });
        });

        // 3. Mark All Notifications Read
        const markAllBtn = document.getElementById('fmm-mark-all-read-btn');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (!confirm('Mark all notifications as read?')) return;

                const token = getCsrf();
                sendEngagementRequest('/multimedia/api/notifications/mark-all-read', { _token: token }, token)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.fmm-notification-item.unread').forEach(el => {
                            el.classList.remove('unread');
                            el.classList.add('read');
                        });
                        document.querySelectorAll('[data-fmm-read-action]').forEach(b => b.remove());
                        markAllBtn.remove();
                        updateBadge('0');
                        showToast('All notifications marked as read.', 'success');
                    }
                });
            });
        }

        // 4. Preferences Modal
        const prefsModal = document.getElementById('fmm-prefs-modal');
        const openPrefsBtn = document.getElementById('fmm-open-prefs-btn');
        const closePrefsBtn = document.getElementById('fmm-close-prefs-btn');
        const cancelPrefsBtn = document.getElementById('fmm-cancel-prefs-btn');
        const prefsForm = document.getElementById('fmm-prefs-form');

        if (prefsModal && openPrefsBtn) {
            openPrefsBtn.addEventListener('click', () => { prefsModal.style.display = 'flex'; });
            if (closePrefsBtn) closePrefsBtn.addEventListener('click', () => { prefsModal.style.display = 'none'; });
            if (cancelPrefsBtn) cancelPrefsBtn.addEventListener('click', () => { prefsModal.style.display = 'none'; });

            if (prefsForm) {
                prefsForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const formData = new FormData(prefsForm);
                    const prefs = {
                        notify_content_updates: formData.has('notify_content_updates'),
                        notify_engagement_replies: formData.has('notify_engagement_replies'),
                        notify_moderation_updates: formData.has('notify_moderation_updates')
                    };

                    const token = getCsrf();
                    sendEngagementRequest('/multimedia/api/notification-preferences', {
                        _token: token,
                        preferences: prefs
                    }, token)
                    .then(res => res.json())
                    .then(data => {
                        prefsModal.style.display = 'none';
                        if (data.success) {
                            showToast('Notification preferences saved.', 'success');
                        } else {
                            showToast('Failed to save preferences.', 'error');
                        }
                    })
                    .catch(() => {
                        showToast('Failed to save preferences.', 'error');
                    });
                });
            }
        }

        function updateBadge(badgeText) {
            const badge = document.getElementById('fmm-main-unread-badge');
            if (badge) {
                if (!badgeText || badgeText === '0') {
                    badge.style.display = 'none';
                } else {
                    badge.style.display = 'inline-flex';
                    badge.textContent = badgeText;
                }
            }
        }
    }

    function init() {
        initEngagement();
        initSubscriptionsAndNotifications();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
